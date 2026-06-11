<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LdapUserSyncService
{
    public function sync(LdapConnection $connection): array
    {
        $startedAt = now();

        $command = $this->buildCommand($connection);
        $displayCommand = $this->displayCommand($connection);

        $execution = CommandExecution::query()->create([
            'command_type' => 'ldap_user_sync',
            'status' => 'running',
            'command' => $displayCommand,
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'mode' => 'dynamic_all_attributes_no_hardcode',
                'attributes_requested' => ['*', '+'],
            ],
            'started_at' => $startedAt,
        ]);

        $tmpFile = storage_path('app/private/ldap-sync/users_'.$connection->id.'_'.date('Ymd_His').'.ldif');

        try {
            @mkdir(dirname($tmpFile), 0775, true);

            $process = new Process($command, base_path());
            $process->setTimeout(1800);
            $process->run();

            $rawStdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            file_put_contents($tmpFile, $rawStdout);

            if (! $process->isSuccessful()) {
                $execution->update([
                    'status' => 'failed',
                    'exit_code' => $process->getExitCode(),
                    'stdout' => $this->summarizeOutput($rawStdout, $tmpFile),
                    'stderr' => $this->redactString($stderr),
                    'error_message' => 'LDAP user sync ldapsearch failed.',
                    'finished_at' => now(),
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

            $entries = $this->parseLdifFile($tmpFile);

            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($entries as $entry) {
                $dn = $this->firstValue($entry['dn'] ?? null);

                if (! filled($dn)) {
                    continue;
                }

                $normalized = $this->normalizeEntry($entry);
                $uid = $normalized['uid'] ?: $this->uidFromDn($dn);

                if (! filled($uid)) {
                    continue;
                }

                $seenDns[] = $dn;

                $model = LdapUserEntry::query()->firstOrNew([
                    'ldap_connection_id' => $connection->id,
                    'dn' => $dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->fill([
                    'uuid' => $model->uuid ?: (string) Str::uuid(),
                    'entry_uuid' => $normalized['entry_uuid'],
                    'uid' => $uid,
                    'cn' => $normalized['cn'],
                    'sn' => $normalized['sn'],
                    'given_name' => $normalized['given_name'],
                    'display_name' => $normalized['display_name'],
                    'mail' => $normalized['mail'],
                    'employee_number' => $normalized['employee_number'],
                    'employee_type' => $normalized['employee_type'],
                    'status' => $normalized['status'],
                    'is_disabled' => $normalized['is_disabled'],
                    'is_locked' => $normalized['is_locked'],
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'operational_attributes' => $normalized['operational_attributes'],
                    'group_dns' => $normalized['group_dns'],
                    'source_hash' => hash('sha256', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ]);

                $model->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            $missing = LdapUserEntry::query()
                ->where('ldap_connection_id', $connection->id)
                ->whereNotIn('dn', $seenDns ?: ['__none__'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('status')
                        ->orWhereNotIn('status', ['deleted_from_ldap']);
                })
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->update([
                'status' => 'success',
                'exit_code' => 0,
                'stdout' => 'LDAP users synced dynamically. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.', Missing: '.$missing.'. LDIF cache: '.$tmpFile,
                'stderr' => $this->redactString($stderr),
                'error_message' => null,
                'finished_at' => now(),
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP users synced successfully.',
                'created' => $created,
                'updated' => $updated,
                'seen' => count($seenDns),
                'missing' => $missing,
                'command_execution_id' => $execution->id,
                'ldif_file' => $tmpFile,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stdout' => isset($rawStdout) ? $this->summarizeOutput($rawStdout, $tmpFile) : null,
                'stderr' => $this->redactString($e->getMessage()),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return [
                'ok' => false,
                'message' => 'LDAP user sync failed.',
                'created' => 0,
                'updated' => 0,
                'seen' => 0,
                'command_execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildCommand(LdapConnection $connection): array
    {
        return [
            'ldapsearch',
            '-LLL',
            '-x',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($connection),
            '-D',
            $this->bindDn($connection),
            '-w',
            $this->bindPassword($connection),
            '-b',
            $this->baseDn($connection),
            $this->userFilter($connection),
            '*',
            '+',
        ];
    }

    private function displayCommand(LdapConnection $connection): string
    {
        return 'ldapsearch -LLL -x -o ldif-wrap=no'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]'
            .' -b '.$this->baseDn($connection)
            .' "'.$this->userFilter($connection).'"'
            .' "*" "+"';
    }

    private function parseLdifFile(string $path): array
    {
        $content = is_file($path) ? file_get_contents($path) : '';

        return $this->parseLdifContent((string) $content);
    }

    private function parseLdifContent(string $content): array
    {
        $entries = [];
        $current = [];
        $lastAttribute = null;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $rawLine) {
            if ($rawLine === '') {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                    $lastAttribute = null;
                }

                continue;
            }

            if (str_starts_with($rawLine, '#')) {
                continue;
            }

            if (str_starts_with($rawLine, ' ') && $lastAttribute !== null) {
                $lastIndex = count($current[$lastAttribute]) - 1;

                if ($lastIndex >= 0) {
                    $current[$lastAttribute][$lastIndex] .= substr($rawLine, 1);
                }

                continue;
            }

            if (! str_contains($rawLine, ':')) {
                continue;
            }

            [$attribute, $value] = explode(':', $rawLine, 2);

            $attribute = trim($attribute);
            $isBase64 = str_starts_with($value, ':');
            $value = $isBase64 ? trim(substr($value, 1)) : trim($value);

            if ($attribute === '') {
                continue;
            }

            if ($isBase64) {
                $decoded = base64_decode($value, true);

                if ($decoded !== false) {
                    $value = $decoded;
                }
            }

            $current[$attribute] ??= [];
            $current[$attribute][] = $value;
            $lastAttribute = $attribute;
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function normalizeEntry(array $entry): array
    {
        $all = [];

        foreach ($entry as $name => $value) {
            $name = (string) $name;

            if ($name === '') {
                continue;
            }

            $all[$name] = $this->asArray($value);
        }

        $normal = [];
        $operational = [];

        foreach ($all as $name => $values) {
            if ($this->isOperationalAttributeName($name)) {
                $operational[$name] = $values;
            } else {
                $normal[$name] = $values;
            }
        }

        ksort($normal, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($operational, SORT_NATURAL | SORT_FLAG_CASE);

        $objectClasses = $this->asArray($normal['objectClass'] ?? $normal['objectclass'] ?? []);
        $memberOf = $this->asArray($operational['memberOf'] ?? $operational['memberof'] ?? []);
        $accountStatus = $this->firstValue($normal['petraAccountStatus'] ?? $normal['petraaccountstatus'] ?? null);

        return [
            'entry_uuid' => $this->firstValue($operational['entryUUID'] ?? $operational['entryuuid'] ?? null),
            'uid' => $this->firstValue($normal['uid'] ?? null),
            'cn' => $this->firstValue($normal['cn'] ?? null),
            'sn' => $this->firstValue($normal['sn'] ?? null),
            'given_name' => $this->firstValue($normal['givenName'] ?? $normal['givenname'] ?? null),
            'display_name' => $this->firstValue($normal['displayName'] ?? $normal['displayname'] ?? null),
            'mail' => $this->firstValue($normal['mail'] ?? null),
            'employee_number' => $this->firstValue($normal['employeeNumber'] ?? $normal['employeenumber'] ?? null),
            'employee_type' => $this->firstValue($normal['employeeType'] ?? $normal['employeetype'] ?? null),
            'status' => filled($accountStatus) ? $accountStatus : 'active',
            'is_disabled' => in_array(strtolower((string) $accountStatus), ['disabled', 'inactive', 'locked'], true),
            'is_locked' => filled($operational['pwdAccountLockedTime'] ?? $operational['pwdaccountlockedtime'] ?? null),
            'object_classes' => $objectClasses,
            'attributes' => $normal,
            'operational_attributes' => $operational,
            'group_dns' => $memberOf,
        ];
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

    private function asArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item): string => trim((string) $item))
                ->filter(fn (string $item): bool => $item !== '')
                ->values()
                ->all();
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
    }

    private function firstValue(mixed $value): ?string
    {
        $values = $this->asArray($value);

        return $values[0] ?? null;
    }

    private function uidFromDn(string $dn): ?string
    {
        if (preg_match('/^uid=([^,]+)/i', $dn, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? '127.0.0.1';
        $port = $connection->port ?? $connection->ldap_port ?? 389;
        $scheme = $connection->scheme ?? 'ldap';

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_dn
            ?? $connection->username
            ?? $connection->user_dn
            ?? 'cn=admin,dc=petra,dc=ac,dc=id'
        );
    }

    private function bindPassword(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_password
            ?? $connection->password
            ?? $connection->bind_pass
            ?? ''
        );
    }

    private function baseDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->base_dn
            ?? $connection->root_dn
            ?? 'dc=petra,dc=ac,dc=id'
        );
    }

    private function userFilter(LdapConnection $connection): string
    {
        return (string) (
            $connection->user_filter
            ?? '(|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=organizationalPerson))'
        );
    }

    private function summarizeOutput(string $stdout, string $path): string
    {
        return 'LDAP raw output stored at '.$path.'. Bytes: '.strlen($stdout).'. Lines: '.substr_count($stdout, "\n").'.';
    }

    private function redactString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/-w\s+\S+/', '-w [REDACTED]', $value);
    }
}
