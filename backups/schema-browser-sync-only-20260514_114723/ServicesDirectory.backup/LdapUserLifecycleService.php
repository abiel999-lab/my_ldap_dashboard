<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LdapUserLifecycleService
{
    public function createUser(array $data): array
    {
        $connection = LdapConnection::query()->find((int) ($data['ldap_connection_id'] ?? 0));

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $parentDn = trim((string) ($data['parent_dn'] ?? ''));
        $rdnAttribute = trim((string) ($data['rdn_attribute'] ?? 'uid'));
        $rdnValue = trim((string) ($data['rdn_value'] ?? ''));
        $structuralObjectClass = trim((string) ($data['structural_object_class'] ?? ''));
        $auxiliaryObjectClasses = $this->cleanList($data['auxiliary_object_classes'] ?? []);
        $attributes = $this->cleanAttributeMap($data['attributes'] ?? []);

        if ($parentDn === '') {
            return $this->logValidationFailureForCreate($connection, $data, 'Parent DN is required.');
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $rdnAttribute)) {
            return $this->logValidationFailureForCreate($connection, $data, 'RDN attribute is invalid.');
        }

        if ($rdnValue === '') {
            return $this->logValidationFailureForCreate($connection, $data, 'RDN value is required.');
        }

        if ($structuralObjectClass === '') {
            return $this->logValidationFailureForCreate($connection, $data, 'Structural objectClass is required.');
        }

        $dn = $rdnAttribute.'='.$this->escapeRdnValue($rdnValue).','.$parentDn;

        $objectClasses = collect(['top', $structuralObjectClass, ...$auxiliaryObjectClasses])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn ($value): string => strtolower($value))
            ->values()
            ->all();

        $attributes[$rdnAttribute] ??= [$rdnValue];

        if (! isset($attributes['cn']) && isset($attributes['displayName'])) {
            $attributes['cn'] = $attributes['displayName'];
        }

        if (! isset($attributes['sn']) && isset($attributes['cn'])) {
            $attributes['sn'] = $attributes['cn'];
        }

        $ldif = $this->buildAddLdif($dn, $objectClasses, $attributes);

        $result = $this->runLdapCommand(
            connection: $connection,
            commandType: 'ldap_user_create',
            operation: 'create_user',
            targetDn: $dn,
            command: [
                'ldapadd',
                '-v',
                '-x',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                '-f',
                $this->writeTempLdif('create_user', $ldif),
            ],
            displayCommandPrefix: 'ldapadd -v -x',
            context: [
                'parent_dn' => $parentDn,
                'rdn_attribute' => $rdnAttribute,
                'rdn_value' => $rdnValue,
                'structural_object_class' => $structuralObjectClass,
                'auxiliary_object_classes' => $auxiliaryObjectClasses,
                'object_classes' => $objectClasses,
                'attributes' => $this->maskAttributeMapForLog($attributes),
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        LdapUserEntry::query()->updateOrCreate(
            [
                'ldap_connection_id' => $connection->id,
                'dn' => $dn,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'uid' => $attributes['uid'][0] ?? ($rdnAttribute === 'uid' ? $rdnValue : null),
                'cn' => $attributes['cn'][0] ?? null,
                'sn' => $attributes['sn'][0] ?? null,
                'mail' => $attributes['mail'][0] ?? null,
                'object_classes' => $objectClasses,
                'attributes' => array_merge($attributes, [
                    'dn' => [$dn],
                    'objectClass' => $objectClasses,
                ]),
                'operational_attributes' => [],
                'group_dns' => [],
                'status' => 'active',
                'last_seen_at' => now(),
                'last_synced_at' => now(),
                'source_hash' => hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]
        );

        return [
            'ok' => true,
            'message' => 'LDAP user created successfully.',
            'command_execution_id' => $result['command_execution_id'] ?? null,
        ];
    }

    public function moveOu(LdapUserEntry $user, string $newParentDn): array
    {
        $newParentDn = trim($newParentDn);

        if ($newParentDn === '') {
            return $this->logValidationFailure($user, 'ldap_user_move_ou_validation_failed', 'move_ou', 'New parent DN is required.');
        }

        $connection = $this->connection($user);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $oldDn = $user->dn;
        $rdn = $this->rdnFromDn($oldDn);
        $newDn = $rdn.','.$newParentDn;

        $result = $this->runLdapCommand(
            connection: $connection,
            commandType: 'ldap_user_move_ou',
            operation: 'move_ou',
            targetDn: $oldDn,
            command: [
                'ldapmodrdn',
                '-v',
                '-x',
                '-r',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                '-s',
                $newParentDn,
                $oldDn,
                $rdn,
            ],
            displayCommandPrefix: 'ldapmodrdn -v -x -r',
            context: [
                'old_dn' => $oldDn,
                'new_parent_dn' => $newParentDn,
                'new_dn' => $newDn,
                'rdn' => $rdn,
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        $user->forceFill([
            'dn' => $newDn,
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'message' => 'User moved successfully to new OU.',
            'command_execution_id' => $result['command_execution_id'] ?? null,
        ];
    }

    public function renameRdn(LdapUserEntry $user, string $newRdnAttribute, string $newRdnValue): array
    {
        $newRdnAttribute = trim($newRdnAttribute);
        $newRdnValue = trim($newRdnValue);

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $newRdnAttribute)) {
            return $this->logValidationFailure($user, 'ldap_user_rename_rdn_validation_failed', 'rename_rdn', 'New RDN attribute is invalid.');
        }

        if ($newRdnValue === '') {
            return $this->logValidationFailure($user, 'ldap_user_rename_rdn_validation_failed', 'rename_rdn', 'New RDN value is required.');
        }

        $connection = $this->connection($user);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $oldDn = $user->dn;
        $parentDn = $this->parentDnFromDn($oldDn);
        $newRdn = $newRdnAttribute.'='.$this->escapeRdnValue($newRdnValue);
        $newDn = $newRdn.','.$parentDn;

        $result = $this->runLdapCommand(
            connection: $connection,
            commandType: 'ldap_user_rename_rdn',
            operation: 'rename_rdn',
            targetDn: $oldDn,
            command: [
                'ldapmodrdn',
                '-v',
                '-x',
                '-r',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                $oldDn,
                $newRdn,
            ],
            displayCommandPrefix: 'ldapmodrdn -v -x -r',
            context: [
                'old_dn' => $oldDn,
                'new_rdn' => $newRdn,
                'new_dn' => $newDn,
                'parent_dn' => $parentDn,
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        $attributes[$newRdnAttribute] = [$newRdnValue];
        $attributes['dn'] = [$newDn];

        $user->forceFill([
            'dn' => $newDn,
            'uid' => $newRdnAttribute === 'uid' ? $newRdnValue : $user->uid,
            'attributes' => $attributes,
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'message' => 'User RDN renamed successfully.',
            'command_execution_id' => $result['command_execution_id'] ?? null,
        ];
    }

    public function deleteUser(LdapUserEntry $user): array
    {
        $connection = $this->connection($user);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $result = $this->runLdapCommand(
            connection: $connection,
            commandType: 'ldap_user_delete',
            operation: 'delete_user',
            targetDn: $user->dn,
            command: [
                'ldapdelete',
                '-v',
                '-x',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                $user->dn,
            ],
            displayCommandPrefix: 'ldapdelete -v -x',
            context: [
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        $user->forceFill([
            'status' => 'deleted_from_ldap',
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'message' => 'LDAP user deleted successfully.',
            'command_execution_id' => $result['command_execution_id'] ?? null,
        ];
    }

    private function runLdapCommand(
        LdapConnection $connection,
        string $commandType,
        string $operation,
        string $targetDn,
        array $command,
        string $displayCommandPrefix,
        array $context = []
    ): array {
        $displayCommand = $displayCommandPrefix
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]';

        if ($operation === 'create_user') {
            $displayCommand .= ' -f [LDIF_FILE]';
        } else {
            $displayCommand .= ' '.$targetDn;
        }

        $execution = CommandExecution::query()->create([
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $displayCommand,
            'environment_context' => array_merge([
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'operation' => $operation,
                'target_dn' => $targetDn,
            ], $context),
            'started_at' => now(),
        ]);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(180);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $execution->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'exit_code' => $process->getExitCode(),
                'stdout' => $this->redact($stdout),
                'stderr' => $this->redact($stderr),
                'error_message' => $process->isSuccessful() ? null : trim($stderr ?: $stdout ?: 'LDAP command failed.'),
                'finished_at' => now(),
            ]);

            if (! $process->isSuccessful()) {
                return $this->failed(trim($stderr ?: $stdout ?: 'LDAP command failed.'), $execution->id);
            }

            return [
                'ok' => true,
                'message' => 'LDAP command succeeded.',
                'command_execution_id' => $execution->id,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stderr' => $this->redact($e->getMessage()),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $this->failed($e->getMessage(), $execution->id);
        }
    }

    private function writeTempLdif(string $name, string $ldif): string
    {
        $dir = storage_path('app/private/ldap-lifecycle');
        @mkdir($dir, 0775, true);

        $file = $dir.'/'.date('Ymd_His').'_'.Str::slug($name).'_'.Str::random(8).'.ldif';
        file_put_contents($file, $ldif);

        return $file;
    }

    private function buildAddLdif(string $dn, array $objectClasses, array $attributes): string
    {
        $lines = [
            'dn: '.$dn,
        ];

        foreach ($objectClasses as $objectClass) {
            $lines[] = 'objectClass: '.$objectClass;
        }

        foreach ($attributes as $attribute => $values) {
            if (strtolower((string) $attribute) === 'objectclass') {
                continue;
            }

            if (strtolower((string) $attribute) === 'dn') {
                continue;
            }

            foreach ($this->cleanList($values) as $value) {
                $lines[] = $attribute.': '.$value;
            }
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function connection(LdapUserEntry $user): ?LdapConnection
    {
        return LdapConnection::query()->find($user->ldap_connection_id);
    }

    private function normalAttributes(LdapUserEntry $user): array
    {
        $raw = $user->getRawOriginal('attributes');

        if (is_array($user->attributes ?? null)) {
            return $user->attributes;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function cleanAttributeMap(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $output = [];

        foreach ($input as $key => $value) {
            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $key)) {
                continue;
            }

            $values = $this->cleanList($value);

            if ($values === []) {
                continue;
            }

            $output[$key] = $values;
        }

        return $output;
    }

    private function cleanList(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/\r\n|\r|\n|,/', $input) ?: [];
        }

        if (! is_array($input)) {
            $input = [$input];
        }

        return collect($input)
            ->flatten()
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function rdnFromDn(string $dn): string
    {
        return explode(',', $dn, 2)[0] ?? $dn;
    }

    private function parentDnFromDn(string $dn): string
    {
        $parts = explode(',', $dn, 2);

        return $parts[1] ?? '';
    }

    private function escapeRdnValue(string $value): string
    {
        return str_replace(
            ['\\', ',', '+', '"', '<', '>', ';'],
            ['\\\\', '\\,', '\\+', '\\"', '\\<', '\\>', '\\;'],
            $value
        );
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

    private function maskAttributeMapForLog(array $attributes): array
    {
        $masked = [];

        foreach ($attributes as $attribute => $values) {
            if (in_array(strtolower((string) $attribute), ['userpassword', 'authpassword', 'unicodepwd', 'pwdhistory'], true)) {
                $masked[$attribute] = ['[PROTECTED VALUE]'];
            } else {
                $masked[$attribute] = $values;
            }
        }

        return $masked;
    }

    private function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }

    private function logValidationFailure(LdapUserEntry $user, string $commandType, string $operation, string $message): array
    {
        $execution = CommandExecution::query()->create([
            'command_type' => $commandType,
            'status' => 'failed',
            'command' => 'validation_before_ldap_command',
            'environment_context' => [
                'ldap_connection_id' => $user->ldap_connection_id,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
                'operation' => $operation,
            ],
            'stderr' => $message,
            'error_message' => $message,
            'exit_code' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $this->failed($message, $execution->id);
    }

    private function logValidationFailureForCreate(LdapConnection $connection, array $data, string $message): array
    {
        $execution = CommandExecution::query()->create([
            'command_type' => 'ldap_user_create_validation_failed',
            'status' => 'failed',
            'command' => 'validation_before_ldapadd',
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'operation' => 'create_user',
                'data' => $data,
            ],
            'stderr' => $message,
            'error_message' => $message,
            'exit_code' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $this->failed($message, $execution->id);
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
