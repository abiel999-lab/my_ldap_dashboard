<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapGroupEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSingleGroupRefreshService
{
    public function refresh(LdapGroupEntry $groupEntry): array
    {
        $startedAt = microtime(true);
        $actor = Auth::user();

        $groupEntry->refresh();

        $connection = $groupEntry->ldapConnection;

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'directory.groups',
            'command_type' => 'ldap_single_group_refresh',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection, $groupEntry->dn) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_group_entry_id' => $groupEntry->id,
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'target_dn' => $groupEntry->dn,
                'read_only' => true,
                'ldap_will_change' => false,
            ]),
            'safe_mode' => true,
            'preview_mode' => true,
            'destructive' => false,
            'started_at' => now(),
        ]);

        if (! $connection) {
            $execution->forceFill([
                'status' => 'blocked',
                'stderr' => 'LDAP connection is missing for this group entry.',
                'exit_code' => 126,
                'error_message' => 'LDAP connection is missing for this group entry.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            return [
                'ok' => false,
                'message' => 'LDAP connection is missing for this group entry.',
                'command_execution_id' => $execution->id,
            ];
        }

        try {
            $process = new Process($this->buildCommand($connection, $groupEntry->dn), base_path());
            $process->setTimeout(90);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => 'LDAP single group refresh failed.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $groupEntry->forceFill([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ])->save();

                $this->audit($groupEntry, $execution, 'failed', [
                    'message' => 'LDAP single group refresh failed.',
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP single group refresh failed. Group may be missing from LDAP.',
                    'command_execution_id' => $execution->id,
                ];
            }

            $entry = $this->parseSingleEntry($stdout);

            if ($entry === []) {
                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => 1,
                    'error_message' => 'LDAP returned no entry for this group DN.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $groupEntry->forceFill([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ])->save();

                $this->audit($groupEntry, $execution, 'failed', [
                    'message' => 'LDAP returned no entry for this group DN.',
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP returned no entry for this group DN.',
                    'command_execution_id' => $execution->id,
                ];
            }

            $normalized = $this->normalizeGroupEntry($entry);
            $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

            $before = [
                'cn' => $groupEntry->cn,
                'ou' => $groupEntry->ou,
                'group_type' => $groupEntry->group_type,
                'member_count' => $groupEntry->member_count,
                'nested_group_count' => $groupEntry->nested_group_count,
                'status' => $groupEntry->status,
                'source_hash' => $groupEntry->source_hash,
            ];

            $groupEntry->forceFill([
                'dn' => $entry['dn'] ?? $groupEntry->dn,
                'entry_uuid' => $normalized['entry_uuid'],
                'cn' => $normalized['cn'],
                'ou' => $normalized['ou'],
                'description' => $normalized['description'],
                'group_type' => $normalized['group_type'],
                'member_count' => $normalized['member_count'],
                'nested_group_count' => $normalized['nested_group_count'],
                'status' => 'active',
                'object_classes' => $normalized['object_classes'],
                'attributes' => $normalized['attributes'],
                'operational_attributes' => $normalized['operational_attributes'],
                'member_dns' => $normalized['member_dns'],
                'member_uids' => $normalized['member_uids'],
                'nested_group_dns' => $normalized['nested_group_dns'],
                'source_hash' => $sourceHash,
                'last_seen_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP single group refreshed successfully. DN: '.$groupEntry->dn,
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $after = [
                'cn' => $groupEntry->cn,
                'ou' => $groupEntry->ou,
                'group_type' => $groupEntry->group_type,
                'member_count' => $groupEntry->member_count,
                'nested_group_count' => $groupEntry->nested_group_count,
                'status' => $groupEntry->status,
                'source_hash' => $groupEntry->source_hash,
            ];

            $this->audit($groupEntry, $execution, 'success', [
                'message' => 'LDAP single group refreshed successfully.',
                'ldap_was_changed' => false,
                'before' => $before,
                'after' => $after,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP group refreshed successfully.',
                'command_execution_id' => $execution->id,
            ];
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($groupEntry, $execution, 'failed', [
                'message' => $exception->getMessage(),
                'ldap_was_changed' => false,
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'command_execution_id' => $execution->id,
            ];
        }
    }

    private function buildCommand(LdapConnection $connection, string $dn): array
    {
        return [
            'ldapsearch',
            '-LLL',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-b',
            $dn,
            '-s',
            'base',
            '(objectClass=*)',
            'dn',
            'entryUUID',
            'cn',
            'ou',
            'description',
            'objectClass',
            'member',
            'uniqueMember',
            'memberUid',
            'owner',
        ];
    }

    private function displayCommand(LdapConnection $connection, string $dn): string
    {
        return 'ldapsearch -LLL -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -b '.$dn
            .' -s base "(objectClass=*)"'
            .' dn entryUUID cn ou description objectClass member uniqueMember memberUid owner';
    }

    private function parseSingleEntry(string $content): array
    {
        $entry = [];

        foreach (preg_split("/\R/", trim($content)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if (isset($entry[$key])) {
                if (! is_array($entry[$key])) {
                    $entry[$key] = [$entry[$key]];
                }

                $entry[$key][] = $value;
            } else {
                $entry[$key] = $value;
            }
        }

        return $entry;
    }

    private function normalizeGroupEntry(array $entry): array
    {
        $objectClasses = $this->asArray($entry['objectClass'] ?? []);

        $members = collect($this->asArray($entry['member'] ?? []))
            ->merge($this->asArray($entry['uniqueMember'] ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $memberUids = $this->asArray($entry['memberUid'] ?? []);

        $nestedGroups = collect($members)
            ->filter(fn (string $dn): bool => str_starts_with(strtolower($dn), 'cn='))
            ->filter(fn (string $dn): bool => str_contains(strtolower($dn), 'ou=groups'))
            ->values()
            ->all();

        return [
            'entry_uuid' => $this->firstValue($entry['entryUUID'] ?? null),
            'cn' => $this->firstValue($entry['cn'] ?? null),
            'ou' => $this->firstValue($entry['ou'] ?? null),
            'description' => $this->firstValue($entry['description'] ?? null),
            'group_type' => $this->detectGroupType($entry, $objectClasses),
            'member_count' => count($members) + count($memberUids),
            'nested_group_count' => count($nestedGroups),
            'object_classes' => $objectClasses,
            'attributes' => $entry,
            'operational_attributes' => [
                'entryUUID' => $entry['entryUUID'] ?? null,
                'owner' => $entry['owner'] ?? null,
            ],
            'member_dns' => $members,
            'member_uids' => $memberUids,
            'nested_group_dns' => $nestedGroups,
        ];
    }

    private function detectGroupType(array $entry, array $objectClasses): string
    {
        $lowerClasses = array_map('strtolower', $objectClasses);
        $dn = strtolower((string) ($entry['dn'] ?? ''));
        $cn = strtolower((string) $this->firstValue($entry['cn'] ?? null));
        $ou = strtolower((string) $this->firstValue($entry['ou'] ?? null));

        if (in_array('organizationalunit', $lowerClasses, true)) {
            return 'organizational_unit';
        }

        if (str_contains($dn, 'ou=apps') || str_contains($cn, 'app-')) {
            return 'app_group';
        }

        if (str_contains($dn, 'ou=roles') || str_contains($cn, 'role')) {
            return 'role_group';
        }

        if (str_contains($dn, 'security') || str_contains($ou, 'security')) {
            return 'security_group';
        }

        if (in_array('posixgroup', $lowerClasses, true)) {
            return 'posix_group';
        }

        return 'ldap_group';
    }

    private function asArray($value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
    }

    private function firstValue($value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function audit(LdapGroupEntry $groupEntry, CommandExecution $execution, string $status, array $context): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.groups',
            'action' => 'refresh_single_ldap_group',
            'status' => $status,
            'target_type' => LdapGroupEntry::class,
            'target_key' => (string) $groupEntry->id,
            'target_dn' => $groupEntry->dn,
            'ldap_connection_id' => $groupEntry->ldap_connection_id,
            'request_payload' => [
                'ldap_group_entry_id' => $groupEntry->id,
                'read_only' => true,
                'ldap_was_changed' => false,
            ],
            'before_value' => $context['before'] ?? null,
            'after_value' => $context['after'] ?? null,
            'command' => $execution->command,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'exit_code' => $execution->exit_code,
            'error_message' => $execution->error_message,
            'duration_ms' => $execution->duration_ms,
        ]);
    }

    private function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(password\s*[=:]\s*)([^\s]+)/i',
            '/(bind_password\s*[=:]\s*)([^\s]+)/i',
            '/(client_secret\s*[=:]\s*)([^\s]+)/i',
            '/(token\s*[=:]\s*)([^\s]+)/i',
            '/(Authorization:\s*Bearer\s+)([^\s]+)/i',
            '/(-w\s+)([^\s]+)/i',
            '/(bindpw:\s*)([^\s]+)/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
