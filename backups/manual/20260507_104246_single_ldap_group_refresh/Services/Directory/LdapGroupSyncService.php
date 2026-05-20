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

class LdapGroupSyncService
{
    public function sync(?LdapConnection $connection = null): array
    {
        $startedAt = microtime(true);
        $actor = Auth::user();

        $connection = $connection ?: LdapConnection::query()->where('is_default', true)->first();

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'directory.groups',
            'command_type' => 'ldap_group_sync',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'base_dn' => $connection?->base_dn,
                'sync_type' => 'groups_foundation_read_only',
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
                'stderr' => 'No default LDAP connection found.',
                'exit_code' => 126,
                'error_message' => 'No default LDAP connection found.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            return [
                'ok' => false,
                'message' => 'No default LDAP connection found.',
                'created' => 0,
                'updated' => 0,
                'seen' => 0,
                'command_execution_id' => $execution->id,
            ];
        }

        try {
            $process = new Process($this->buildCommand($connection), base_path());
            $process->setTimeout(180);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => 'LDAP group sync ldapsearch failed.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $this->audit($connection, $execution, 'failed', [
                    'seen' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP group sync failed.',
                    'created' => 0,
                    'updated' => 0,
                    'seen' => 0,
                    'command_execution_id' => $execution->id,
                ];
            }

            $entries = $this->parseLdifEntries($stdout);
            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($entries as $entry) {
                $dn = $entry['dn'] ?? null;

                if (blank($dn)) {
                    continue;
                }

                $seenDns[] = $dn;

                $normalized = $this->normalizeGroupEntry($entry);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapGroupEntry::query()->firstOrNew([
                    'ldap_connection_id' => $connection->id,
                    'dn' => $dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
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

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapGroupEntry::query()
                ->where('ldap_connection_id', $connection->id)
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP groups synced. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($connection, $execution, 'success', [
                'seen' => count($seenDns),
                'created' => $created,
                'updated' => $updated,
                'ldap_was_changed' => false,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP groups synced successfully.',
                'created' => $created,
                'updated' => $updated,
                'seen' => count($seenDns),
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

            $this->audit($connection, $execution, 'failed', [
                'seen' => 0,
                'created' => 0,
                'updated' => 0,
                'ldap_was_changed' => false,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'created' => 0,
                'updated' => 0,
                'seen' => 0,
                'command_execution_id' => $execution->id,
            ];
        }
    }

    private function buildCommand(LdapConnection $connection): array
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
            $connection->base_dn,
            '(|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=posixGroup)(objectClass=organizationalUnit))',
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

    private function displayCommand(LdapConnection $connection): string
    {
        return 'ldapsearch -LLL -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -b '.$connection->base_dn
            .' "(|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=posixGroup)(objectClass=organizationalUnit))"'
            .' dn entryUUID cn ou description objectClass member uniqueMember memberUid owner';
    }

    private function parseLdifEntries(string $content): array
    {
        $blocks = preg_split("/\R{2,}/", trim($content)) ?: [];
        $entries = [];

        foreach ($blocks as $block) {
            $entry = [];

            foreach (preg_split("/\R/", trim($block)) ?: [] as $line) {
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

            if ($entry !== []) {
                $entries[] = $entry;
            }
        }

        return $entries;
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

        $groupType = $this->detectGroupType($entry, $objectClasses);

        return [
            'entry_uuid' => $this->firstValue($entry['entryUUID'] ?? null),
            'cn' => $this->firstValue($entry['cn'] ?? null),
            'ou' => $this->firstValue($entry['ou'] ?? null),
            'description' => $this->firstValue($entry['description'] ?? null),
            'group_type' => $groupType,
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
        $dn = strtolower((string) ($entry['dn'] ?? ''));
        $cn = strtolower((string) $this->firstValue($entry['cn'] ?? null));
        $ou = strtolower((string) $this->firstValue($entry['ou'] ?? null));

        if (in_array('organizationalUnit', $objectClasses, true) || in_array('organizationalunit', array_map('strtolower', $objectClasses), true)) {
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

        if (in_array('posixGroup', $objectClasses, true) || in_array('posixgroup', array_map('strtolower', $objectClasses), true)) {
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

    private function audit(LdapConnection $connection, CommandExecution $execution, string $status, array $summary): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.groups',
            'action' => 'sync_ldap_groups',
            'status' => $status,
            'target_type' => LdapConnection::class,
            'target_key' => (string) $connection->id,
            'target_dn' => $connection->base_dn,
            'ldap_connection_id' => $connection->id,
            'request_payload' => [
                'filter' => '(|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=posixGroup)(objectClass=organizationalUnit))',
                'read_only' => true,
                'ldap_was_changed' => false,
            ],
            'after_value' => $summary,
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
