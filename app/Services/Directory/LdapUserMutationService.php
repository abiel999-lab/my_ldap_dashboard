<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class LdapUserMutationService
{
    public function addAttribute(LdapUserEntry $user, string $attribute, array|string $values): array
    {
        $attribute = $this->cleanAttributeName($attribute);
        $values = $this->cleanValues($values);

        $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($user, $attribute);

        [$valid, $message] = app(LdapSchemaAttributeCatalogService::class)->validateAdd($user, $attribute, $values);

        if (! $valid) {
            $commandExecutionId = $this->logValidationFailure(
                $user,
                'ldap_user_add_attribute_validation_failed',
                'add_attribute',
                $attribute,
                $values,
                $message ?? 'Add attribute validation failed.',
                $meta
            );

            return $this->failed($message ?? 'Add attribute validation failed.', $commandExecutionId);
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'add',
                'attribute' => $attribute,
                'values' => $values,
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_add_attribute', 'add', $attribute, $values, $meta);

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        $attributes[$attribute] = $values;

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute added successfully.',
        ];
    }

    public function replaceAttribute(LdapUserEntry $user, string $attribute, array|string $values): array
    {
        $attribute = $this->cleanAttributeName($attribute);
        $values = $this->cleanValues($values);

        $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($user, $attribute);

        [$valid, $message] = app(LdapSchemaAttributeCatalogService::class)->validateReplace($user, $attribute, $values);

        if (! $valid) {
            $commandExecutionId = $this->logValidationFailure(
                $user,
                'ldap_user_replace_attribute_validation_failed',
                'replace_attribute',
                $attribute,
                $values,
                $message ?? 'Replace attribute validation failed.',
                $meta
            );

            return $this->failed($message ?? 'Replace attribute validation failed.', $commandExecutionId);
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'replace',
                'attribute' => $attribute,
                'values' => $values,
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_replace_attribute', 'replace', $attribute, $values, $meta);

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        $attributes[$attribute] = $values;

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute replaced successfully.',
        ];
    }

    public function removeAttribute(LdapUserEntry $user, string $attribute): array
    {
        $attribute = $this->cleanAttributeName($attribute);

        $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($user, $attribute);

        [$valid, $message] = app(LdapSchemaAttributeCatalogService::class)->validateRemove($user, $attribute);

        if (! $valid) {
            $commandExecutionId = $this->logValidationFailure(
                $user,
                'ldap_user_remove_attribute_validation_failed',
                'remove_attribute',
                $attribute,
                [],
                $message ?? 'Remove attribute validation failed.',
                $meta
            );

            return $this->failed($message ?? 'Remove attribute validation failed.', $commandExecutionId);
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'delete',
                'attribute' => $attribute,
                'values' => [],
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_remove_attribute', 'delete', $attribute, [], $meta);

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        unset($attributes[$attribute]);

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute removed successfully.',
        ];
    }
public function addObjectClass(LdapUserEntry $user, string $objectClass, array $mustValues = []): array
    {
        $objectClass = $this->cleanObjectClassName($objectClass);

        $cleanMustValues = [];

        foreach ($mustValues as $attribute => $values) {
            $attribute = $this->cleanAttributeName((string) $attribute);

            if ($attribute === '') {
                continue;
            }

            $values = $this->cleanValues($values);

            if ($values !== []) {
                $cleanMustValues[$attribute] = $values;
            }
        }

        [$valid, $message] = app(LdapSchemaAttributeCatalogService::class)->validateAddObjectClass($user, $objectClass, $cleanMustValues);

        if (! $valid) {
            return $this->failed($message ?? 'Add objectClass validation failed.');
        }

        $meta = app(LdapSchemaAttributeCatalogService::class)->objectClassMetadata($user, $objectClass);

        $changes = [
            [
                'operation' => 'add',
                'attribute' => 'objectClass',
                'values' => [$objectClass],
            ],
        ];

        foreach ($cleanMustValues as $attribute => $values) {
            $changes[] = [
                'operation' => 'add',
                'attribute' => $attribute,
                'values' => $values,
            ];
        }

        $ldif = $this->buildModifyLdif($user->dn, $changes);

        $result = $this->applyLdif(
            $user,
            $ldif,
            'ldap_user_add_objectclass',
            'add_objectclass',
            'objectClass',
            [$objectClass],
            [
                'single_value' => false,
                'required' => false,
                'source_object_classes' => [$objectClass],
                'source' => 'ldap_schema_objectclass',
                'object_class_meta' => $meta,
                'must_values' => $this->maskMustValuesForLog($cleanMustValues),
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        $objectClasses = $this->values($user->object_classes ?? []);
        $objectClasses[] = $objectClass;
        $objectClasses = collect($objectClasses)->unique()->values()->all();

        $attributes = $this->normalAttributes($user);
        $attributes['objectClass'] = $objectClasses;

        foreach ($cleanMustValues as $attribute => $values) {
            $attributes[$attribute] = $values;
        }

        ksort($attributes, SORT_NATURAL | SORT_FLAG_CASE);

        $user->forceFill([
            'object_classes' => $objectClasses,
            'attributes' => $attributes,
            'source_hash' => hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $this->refreshUserMirrorAfterWrite($user);
        $user->refresh();

        return $result + [
            'message' => 'ObjectClass added successfully.',
        ];
    }
public function removeObjectClass(LdapUserEntry $user, string $objectClass): array
    {
        $objectClass = $this->cleanObjectClassName($objectClass);

        $meta = app(LdapSchemaAttributeCatalogService::class)->objectClassMetadata($user, $objectClass);

        [$valid, $message] = app(LdapSchemaAttributeCatalogService::class)->validateRemoveObjectClass($user, $objectClass);

        if (! $valid) {
            $commandExecutionId = $this->logValidationFailure(
                $user,
                'ldap_user_remove_objectclass_validation_failed',
                'remove_objectclass',
                'objectClass',
                [$objectClass],
                $message ?? 'Remove objectClass validation failed.',
                [
                    'single_value' => false,
                    'required' => false,
                    'source_object_classes' => [$objectClass],
                    'source' => 'ldap_schema_objectclass',
                    'object_class_meta' => $meta,
                ]
            );

            return $this->failed($message ?? 'Remove objectClass validation failed.', $commandExecutionId);
        }

        $dependentAttributes = app(LdapSchemaAttributeCatalogService::class)
            ->dependentAttributesForRemovingObjectClass($user, $objectClass);

        $changes = [];

        foreach ($dependentAttributes as $attribute => $values) {
            $changes[] = [
                'operation' => 'delete',
                'attribute' => $attribute,
                'values' => [],
            ];
        }

        $changes[] = [
            'operation' => 'delete',
            'attribute' => 'objectClass',
            'values' => [$objectClass],
        ];

        $ldif = $this->buildModifyLdif($user->dn, $changes);

        $result = $this->applyLdif(
            $user,
            $ldif,
            'ldap_user_remove_objectclass',
            'remove_objectclass',
            'objectClass',
            [$objectClass],
            [
                'single_value' => false,
                'required' => false,
                'source_object_classes' => [$objectClass],
                'source' => 'ldap_schema_objectclass',
                'object_class_meta' => $meta,
                'cascade_deleted_attributes' => array_keys($dependentAttributes),
            ]
        );

        if (! $result['ok']) {
            return $result;
        }

        $objectClasses = collect($this->values($user->object_classes ?? []))
            ->reject(fn (string $name): bool => strtolower($name) === strtolower($objectClass))
            ->values()
            ->all();

        $attributes = $this->normalAttributes($user);
        $attributes['objectClass'] = $objectClasses;

        foreach (array_keys($dependentAttributes) as $attribute) {
            unset($attributes[$attribute]);
        }

        ksort($attributes, SORT_NATURAL | SORT_FLAG_CASE);

        $user->forceFill([
            'object_classes' => $objectClasses,
            'attributes' => $attributes,
            'source_hash' => hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $this->refreshUserMirrorAfterWrite($user);
        $user->refresh();

        return $result + [
            'message' => 'ObjectClass removed successfully. Dependent attributes removed: '.count($dependentAttributes).'.',
        ];
    }


    private function logValidationFailure(
        LdapUserEntry $user,
        string $commandType,
        string $operation,
        string $attribute,
        array $values,
        string $message,
        array $schemaMeta = []
    ): int {
        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        $execution = CommandExecution::query()->create([
            'command_type' => $commandType,
            'status' => 'failed',
            'command' => 'validation_before_ldapmodify',
            'environment_context' => [
                'ldap_connection_id' => $user->ldap_connection_id,
                'ldap_connection_name' => $connection?->name,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
                'operation' => $operation,
                'attribute' => $attribute,
                'value_count' => count($values),
                'values' => $this->maskValuesForLog($attribute, $values),
                'schema' => [
                    'single_value' => (bool) ($schemaMeta['single_value'] ?? false),
                    'required' => (bool) ($schemaMeta['required'] ?? false),
                    'syntax_oid' => $schemaMeta['syntax_oid'] ?? null,
                    'value_type' => $schemaMeta['value_type'] ?? null,
                    'source_object_classes' => $schemaMeta['source_object_classes'] ?? [],
                    'source' => $schemaMeta['source'] ?? null,
                ],
                'validation_stage' => 'before_ldapmodify',
            ],
            'stdout' => null,
            'stderr' => $message,
            'exit_code' => 1,
            'error_message' => $message,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $execution->id;
    }


    private function applyLdif(
        LdapUserEntry $user,
        string $ldif,
        string $commandType,
        string $operation,
        string $attribute,
        array $values,
        array $schemaMeta
    ): array {
        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $tmpDir = storage_path('app/private/ldap-mutations/users');
        @mkdir($tmpDir, 0775, true);

        $tmpFile = $tmpDir.'/'.date('Ymd_His').'_'.Str::slug($commandType).'_'.$user->id.'.ldif';

        file_put_contents($tmpFile, $ldif);

        $command = [
            'ldapmodify',
            '-v',
            '-x',
            '-H',
            $this->ldapUri($connection),
            '-D',
            $this->bindDn($connection),
            '-w',
            $this->bindPassword($connection),
            '-f',
            $tmpFile,
        ];

        $displayCommand = 'ldapmodify -v -x'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]'
            .' -f '.$tmpFile;

        $execution = CommandExecution::query()->create([
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $displayCommand,
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
                'ldif_file' => $tmpFile,
                'operation' => $operation,
                'attribute' => $attribute,
                'value_count' => count($values),
                'values' => $this->maskValuesForLog($attribute, $values),
                'schema' => [
                    'single_value' => (bool) ($schemaMeta['single_value'] ?? false),
                    'required' => (bool) ($schemaMeta['required'] ?? false),
                    'source_object_classes' => $schemaMeta['source_object_classes'] ?? [],
                    'source' => $schemaMeta['source'] ?? null,
                ],
            ],
            'started_at' => now(),
        ]);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $execution->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'exit_code' => $process->getExitCode(),
                'stdout' => $this->redactSensitiveText($stdout),
                'stderr' => $this->redactSensitiveText($stderr),
                'error_message' => $process->isSuccessful() ? null : trim($stderr ?: $stdout ?: 'ldapmodify failed.'),
                'finished_at' => now(),
            ]);

            if (! $process->isSuccessful()) {
                return $this->failed(
                    trim($stderr ?: $stdout ?: 'ldapmodify failed.'),
                    $execution->id
                );
            }

            return [
                'ok' => true,
                'message' => 'LDAP modify succeeded.',
                'command_execution_id' => $execution->id,
                'ldif_file' => $tmpFile,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stdout' => null,
                'stderr' => $this->redactSensitiveText($e->getMessage()),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $this->failed($e->getMessage(), $execution->id);
        }
    }

    private function refreshUserMirrorAfterWrite(LdapUserEntry $user): void
    {
        try {
            app(LdapSingleUserSyncService::class)->sync($user->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function buildModifyLdif(string $dn, array $changes): string
    {
        if (! filled($dn)) {
            throw new RuntimeException('DN is required.');
        }

        $lines = [
            'dn: '.$dn,
            'changetype: modify',
        ];

        foreach ($changes as $index => $change) {
            $operation = $change['operation'];
            $attribute = $change['attribute'];
            $values = $change['values'] ?? [];

            if ($index > 0) {
                $lines[] = '-';
            }

            $lines[] = $operation.': '.$attribute;

            foreach ($values as $value) {
                $lines[] = $attribute.': '.$this->formatLdifValue((string) $value);
            }
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function formatLdifValue(string $value): string
    {
        return $value;
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

    private function saveNormalAttributes(LdapUserEntry $user, array $attributes): void
    {
        $this->refreshUserMirrorAfterWrite($user);
        $user->refresh();
    }

    private function cleanAttributeName(string $attribute): string
    {
        $attribute = trim($attribute);

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $attribute)) {
            return '';
        }

        return $attribute;
    }

    private function cleanValues(array|string $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/\r\n|\r|\n|,/', $values) ?: [];
        }

        return collect($values)
            ->flatten()
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }


    private function cleanObjectClassName(string $objectClass): string
    {
        $objectClass = trim($objectClass);

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $objectClass)) {
            return '';
        }

        return $objectClass;
    }


    private function values(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->values($decoded);
            }

            return [$value];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return [(string) $value];
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


    private function maskMustValuesForLog(array $mustValues): array
    {
        $masked = [];

        foreach ($mustValues as $attribute => $values) {
            $masked[$attribute] = $this->maskValuesForLog((string) $attribute, (array) $values);
        }

        return $masked;
    }

    private function maskValuesForLog(string $attribute, array $values): array
    {
        if (in_array(strtolower($attribute), ['userpassword', 'authpassword', 'unicodepwd', 'pwdhistory'], true)) {
            return array_fill(0, count($values), '[PROTECTED VALUE]');
        }

        return $values;
    }

    private function redactSensitiveText(?string $text): ?string
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
