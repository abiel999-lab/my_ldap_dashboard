<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSingleUserSyncService
{
    public function sync(LdapUserEntry $user): array
    {
        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $command = [
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
            $user->dn,
            '-s',
            'base',
            '(objectClass=*)',
            '*',
            '+',
        ];

        $displayCommand = 'ldapsearch -LLL -x -o ldif-wrap=no'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]'
            .' -b '.$user->dn
            .' -s base "(objectClass=*)" "*" "+"';

        $execution = CommandExecution::query()->create([
            'command_type' => 'ldap_single_user_sync',
            'status' => 'running',
            'command' => $displayCommand,
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
            ],
            'started_at' => now(),
        ]);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            if (! $process->isSuccessful()) {
                $execution->update([
                    'status' => 'failed',
                    'exit_code' => $process->getExitCode(),
                    'stdout' => $this->redact($stdout),
                    'stderr' => $this->redact($stderr),
                    'error_message' => trim($stderr ?: $stdout ?: 'ldapsearch failed.'),
                    'finished_at' => now(),
                ]);

                return $this->failed(trim($stderr ?: $stdout ?: 'ldapsearch failed.'), $execution->id);
            }

            $parsed = $this->parseLdif($stdout);

            if ($parsed === []) {
                $user->forceFill([
                    'status' => 'missing_from_ldap',
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $execution->update([
                    'status' => 'success',
                    'exit_code' => 0,
                    'stdout' => $this->redact($stdout),
                    'stderr' => $this->redact($stderr),
                    'error_message' => null,
                    'environment_context' => array_merge((array) $execution->environment_context, [
                        'result' => 'missing_from_ldap',
                    ]),
                    'finished_at' => now(),
                ]);

                return [
                    'ok' => true,
                    'message' => 'User not found in LDAP. Marked as missing_from_ldap.',
                    'command_execution_id' => $execution->id,
                ];
            }

            $normal = [];
            $operational = [];

            foreach ($parsed as $attribute => $values) {
                if ($this->isOperationalAttribute($attribute)) {
                    $operational[$attribute] = $values;
                } else {
                    $normal[$attribute] = $values;
                }
            }

            $objectClasses = $normal['objectClass'] ?? $normal['objectclass'] ?? [];
            $memberOf = $operational['memberOf'] ?? $operational['memberof'] ?? [];

            $user->forceFill([
                'dn' => $parsed['dn'][0] ?? $user->dn,
                'uid' => $normal['uid'][0] ?? $user->uid,
                'cn' => $normal['cn'][0] ?? $user->cn,
                'sn' => $normal['sn'][0] ?? $user->sn,
                'mail' => $normal['mail'][0] ?? $user->mail,
                'object_classes' => array_values($objectClasses),
                'attributes' => $normal,
                'operational_attributes' => $operational,
                'group_dns' => array_values($memberOf),
                'status' => 'active',
                'last_seen_at' => now(),
                'last_synced_at' => now(),
                'source_hash' => hash('sha256', json_encode([
                    'normal' => $normal,
                    'operational' => $operational,
                    'group_dns' => $memberOf,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ])->save();

            $execution->update([
                'status' => 'success',
                'exit_code' => 0,
                'stdout' => $this->redact($stdout),
                'stderr' => $this->redact($stderr),
                'error_message' => null,
                'environment_context' => array_merge((array) $execution->environment_context, [
                    'result' => 'synced',
                    'normal_attribute_count' => count($normal),
                    'operational_attribute_count' => count($operational),
                    'object_class_count' => count($objectClasses),
                    'membership_count' => count($memberOf),
                ]),
                'finished_at' => now(),
            ]);

            return [
                'ok' => true,
                'message' => 'Single user synced successfully.',
                'command_execution_id' => $execution->id,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stdout' => null,
                'stderr' => $this->redact($e->getMessage()),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $this->failed($e->getMessage(), $execution->id);
        }
    }

    private function parseLdif(string $ldif): array
    {
        $result = [];
        $currentAttribute = null;

        foreach (preg_split('/\r\n|\r|\n/', $ldif) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ' ')) {
                if ($currentAttribute !== null) {
                    $lastIndex = count($result[$currentAttribute]) - 1;
                    $result[$currentAttribute][$lastIndex] .= substr($line, 1);
                }

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$attribute, $value] = explode(':', $line, 2);

            $attribute = trim($attribute);
            $value = ltrim($value);

            if ($attribute === '') {
                continue;
            }

            $currentAttribute = $attribute;
            $result[$attribute] ??= [];
            $result[$attribute][] = $value;
        }

        return $result;
    }

    private function isOperationalAttribute(string $attribute): bool
    {
        return in_array(strtolower($attribute), [
            'entryuuid',
            'entrycsn',
            'entrydn',
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
            'memberof',
        ], true);
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

    private function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }

    private function failed(string $message, ?int $commandExecutionId = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'command_execution_id' => $commandExecutionId,
        ];
    }
}
