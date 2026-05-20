<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSingleUserRefreshService
{
    public function refresh(LdapUserEntry $userEntry): array
    {
        $startedAt = microtime(true);
        $actor = Auth::user();

        $userEntry->refresh();

        $connection = $userEntry->ldapConnection;

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'directory.users',
            'command_type' => 'ldap_single_user_refresh',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection, $userEntry->dn) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_user_entry_id' => $userEntry->id,
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'target_dn' => $userEntry->dn,
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
                'stderr' => 'LDAP connection is missing for this user entry.',
                'exit_code' => 126,
                'error_message' => 'LDAP connection is missing for this user entry.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            return [
                'ok' => false,
                'message' => 'LDAP connection is missing for this user entry.',
                'command_execution_id' => $execution->id,
            ];
        }

        try {
            $process = new Process($this->buildCommand($connection, $userEntry->dn), base_path());
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
                    'error_message' => 'LDAP single user refresh failed.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $userEntry->forceFill([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ])->save();

                $this->audit($userEntry, $execution, 'failed', [
                    'message' => 'LDAP single user refresh failed.',
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP single user refresh failed. User may be missing from LDAP.',
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
                    'error_message' => 'LDAP returned no entry for this DN.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $userEntry->forceFill([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ])->save();

                $this->audit($userEntry, $execution, 'failed', [
                    'message' => 'LDAP returned no entry for this DN.',
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP returned no entry for this DN.',
                    'command_execution_id' => $execution->id,
                ];
            }

            $normalized = $this->normalizeUserEntry($entry);
            $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

            $before = [
                'uid' => $userEntry->uid,
                'cn' => $userEntry->cn,
                'mail' => $userEntry->mail,
                'status' => $userEntry->status,
                'source_hash' => $userEntry->source_hash,
            ];

            $userEntry->forceFill([
                'dn' => $entry['dn'] ?? $userEntry->dn,
                'entry_uuid' => $normalized['entry_uuid'],
                'uid' => $normalized['uid'],
                'cn' => $normalized['cn'],
                'sn' => $normalized['sn'],
                'given_name' => $normalized['given_name'],
                'display_name' => $normalized['display_name'],
                'mail' => $normalized['mail'],
                'employee_number' => $normalized['employee_number'],
                'employee_type' => $normalized['employee_type'],
                'status' => 'active',
                'is_disabled' => false,
                'is_locked' => filled($normalized['operational_attributes']['pwdAccountLockedTime'] ?? null),
                'object_classes' => $normalized['object_classes'],
                'attributes' => $normalized['attributes'],
                'operational_attributes' => $normalized['operational_attributes'],
                'group_dns' => $normalized['group_dns'],
                'source_hash' => $sourceHash,
                'last_seen_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP single user refreshed successfully. DN: '.$userEntry->dn,
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $after = [
                'uid' => $userEntry->uid,
                'cn' => $userEntry->cn,
                'mail' => $userEntry->mail,
                'status' => $userEntry->status,
                'source_hash' => $userEntry->source_hash,
            ];

            $this->audit($userEntry, $execution, 'success', [
                'message' => 'LDAP single user refreshed successfully.',
                'ldap_was_changed' => false,
                'before' => $before,
                'after' => $after,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP user refreshed successfully.',
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

            $this->audit($userEntry, $execution, 'failed', [
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
            'uid',
            'cn',
            'sn',
            'givenName',
            'displayName',
            'mail',
            'employeeNumber',
            'employeeType',
            'objectClass',
            'memberOf',
            'pwdAccountLockedTime',
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
            .' "*" "+"';
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

    private function normalizeUserEntry(array $entry): array
    {
        $objectClasses = $this->asArray($entry['objectClass'] ?? []);
        $memberOf = $this->asArray($entry['memberOf'] ?? []);

        return [
            'entry_uuid' => $this->firstValue($entry['entryUUID'] ?? null),
            'uid' => $this->firstValue($entry['uid'] ?? null),
            'cn' => $this->firstValue($entry['cn'] ?? null),
            'sn' => $this->firstValue($entry['sn'] ?? null),
            'given_name' => $this->firstValue($entry['givenName'] ?? null),
            'display_name' => $this->firstValue($entry['displayName'] ?? null),
            'mail' => $this->firstValue($entry['mail'] ?? null),
            'employee_number' => $this->firstValue($entry['employeeNumber'] ?? null),
            'employee_type' => $this->firstValue($entry['employeeType'] ?? null),
            'object_classes' => $objectClasses,
            'attributes' => $entry,
            'operational_attributes' => [
                'entryUUID' => $entry['entryUUID'] ?? null,
                'pwdAccountLockedTime' => $entry['pwdAccountLockedTime'] ?? null,
            ],
            'group_dns' => $memberOf,
        ];
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

    private function audit(LdapUserEntry $userEntry, CommandExecution $execution, string $status, array $context): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.users',
            'action' => 'refresh_single_ldap_user',
            'status' => $status,
            'target_type' => LdapUserEntry::class,
            'target_key' => (string) $userEntry->id,
            'target_dn' => $userEntry->dn,
            'ldap_connection_id' => $userEntry->ldap_connection_id,
            'request_payload' => [
                'ldap_user_entry_id' => $userEntry->id,
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

private function isOperationalAttributeName(string $name): bool
    {
        return in_array(strtolower($name), [
            'entryuuid',
            'entrycsn',
            'createtimestamp',
            'modifytimestamp',
            'creatorsname',
            'modifiersname',
            'subschemasubentry',
            'hassubordinates',
            'structuralobjectclass',
            'contextcsn',
            'pwdchangedtime',
            'pwdaccountlockedtime',
            'pwdhistory',
            'entrydn',
            'memberof',
        ], true);
    }

}
