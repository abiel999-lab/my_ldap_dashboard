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

class LdapUserSyncService
{
    public function sync(?LdapConnection $connection = null): array
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $connection = $connection ?: LdapConnection::query()->where('is_default', true)->first();

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'directory.users',
            'command_type' => 'ldap_user_sync',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'base_dn' => $connection?->base_dn,
                'sync_type' => 'users_foundation_read_only',
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
            $command = $this->buildCommand($connection);
            $process = new Process($command, base_path());
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
                    'error_message' => 'LDAP user sync ldapsearch failed.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                app(AuditLogger::class)->log([
                    'module' => 'directory.users',
                    'action' => 'sync_ldap_users',
                    'status' => 'failed',
                    'target_type' => LdapConnection::class,
                    'target_key' => (string) $connection->id,
                    'target_dn' => $connection->base_dn,
                    'ldap_connection_id' => $connection->id,
                    'command' => $execution->command,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => $execution->error_message,
                    'duration_ms' => $execution->duration_ms,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP user sync failed.',
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

                $normalized = $this->normalizeUserEntry($entry);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapUserEntry::query()->firstOrNew([
                    'ldap_connection_id' => $connection->id,
                    'dn' => $dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
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
                    'is_locked' => false,
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'operational_attributes' => $normalized['operational_attributes'],
                    'group_dns' => $normalized['group_dns'],
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapUserEntry::query()
                ->where('ldap_connection_id', $connection->id)
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP users synced. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'sync_ldap_users',
                'status' => 'success',
                'target_type' => LdapConnection::class,
                'target_key' => (string) $connection->id,
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'request_payload' => [
                    'filter' => '(|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=organizationalPerson))',
                    'read_only' => true,
                    'ldap_was_changed' => false,
                ],
                'after_value' => [
                    'seen' => count($seenDns),
                    'created' => $created,
                    'updated' => $updated,
                ],
                'command' => $execution->command,
                'stdout' => $execution->stdout,
                'stderr' => $execution->stderr,
                'exit_code' => $execution->exit_code,
                'duration_ms' => $execution->duration_ms,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP users synced successfully.',
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

            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'sync_ldap_users',
                'status' => 'failed',
                'target_type' => LdapConnection::class,
                'target_key' => (string) $connection->id,
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'command' => $execution->command,
                'stderr' => $execution->stderr,
                'exit_code' => $execution->exit_code,
                'error_message' => $execution->error_message,
                'duration_ms' => $execution->duration_ms,
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
            '(|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=organizationalPerson))',
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

    private function displayCommand(LdapConnection $connection): string
    {
        return 'ldapsearch -LLL -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -b '.$connection->base_dn
            .' "(|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=organizationalPerson))"'
            .' "*" "+"';
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
