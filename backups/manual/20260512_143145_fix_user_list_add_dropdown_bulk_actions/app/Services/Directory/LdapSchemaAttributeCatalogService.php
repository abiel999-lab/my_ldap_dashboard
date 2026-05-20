<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSchemaAttributeCatalogService
{
    public function addAttributeOptions(LdapUserEntry $user): array
    {
        $existing = $this->existingNormalAttributeNames($user);
        $allowed = $this->allowedAttributeMetadataForUser($user);

        $options = [];

        foreach ($allowed as $name => $meta) {
            if (isset($existing[strtolower($name)])) {
                continue;
            }

            if ($this->isProtectedAttribute($name)) {
                continue;
            }

            if (! $this->isManuallyEditableAttributeMeta($meta)) {
                continue;
            }

            $options[$name] = $this->attributeLabel($name, $meta);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function replaceAttributeOptions(LdapUserEntry $user): array
    {
        $attributes = $this->normalAttributes($user);
        $allowed = $this->allowedAttributeMetadataForUser($user);

        $options = [];

        foreach ($attributes as $name => $values) {
            if ($this->isProtectedAttribute($name)) {
                continue;
            }

            if (strtolower($name) === 'objectclass') {
                continue;
            }

            $key = $this->findCaseInsensitiveKey($allowed, $name);

            $meta = $key
                ? $allowed[$key]
                : [
                    'name' => $name,
                    'single_value' => count($this->values($values)) <= 1,
                    'required' => false,
                    'source_object_classes' => ['existing attribute'],
                    'syntax_oid' => null,
                    'value_type' => 'unknown',
                    'source' => 'fallback_existing_attribute',
                ];

            $options[$name] = $this->attributeLabel($name, $meta);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function removeAttributeOptions(LdapUserEntry $user): array
    {
        $attributes = $this->normalAttributes($user);
        $allowed = $this->allowedAttributeMetadataForUser($user);

        $options = [];

        foreach ($attributes as $name => $values) {
            if ($this->isProtectedAttribute($name)) {
                continue;
            }

            if (strtolower($name) === 'objectclass') {
                continue;
            }

            $key = $this->findCaseInsensitiveKey($allowed, $name);

            $meta = $key
                ? $allowed[$key]
                : [
                    'name' => $name,
                    'single_value' => count($this->values($values)) <= 1,
                    'required' => false,
                    'source_object_classes' => ['existing attribute'],
                    'syntax_oid' => null,
                    'value_type' => 'unknown',
                    'source' => 'fallback_existing_attribute',
                ];

            if ($meta['required'] ?? false) {
                continue;
            }

            if (! $this->isManuallyEditableAttributeMeta($meta)) {
                continue;
            }

            $options[$name] = $this->attributeLabel($name, $meta);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function attributeMetadataForUser(LdapUserEntry $user, string $attribute): array
    {
        $attribute = trim($attribute);
        $allowed = $this->allowedAttributeMetadataForUser($user);

        foreach ($allowed as $name => $meta) {
            if (strtolower($name) === strtolower($attribute)) {
                return $meta;
            }
        }

        $attributes = $this->normalAttributes($user);

        foreach ($attributes as $name => $values) {
            if (strtolower($name) === strtolower($attribute)) {
                return [
                    'name' => $name,
                    'single_value' => count($this->values($values)) <= 1,
                    'required' => false,
                    'source_object_classes' => ['existing attribute'],
                    'syntax_oid' => null,
                    'value_type' => 'unknown',
                    'source' => 'fallback_existing_attribute',
                ];
            }
        }

        return [
            'name' => $attribute,
            'single_value' => false,
            'required' => false,
            'source_object_classes' => [],
            'syntax_oid' => null,
            'value_type' => 'unknown',
            'source' => 'unknown',
        ];
    }

    public function validateAdd(LdapUserEntry $user, string $attribute, array $values): array
    {
        $attribute = trim($attribute);

        if ($attribute === '') {
            return [false, 'Attribute is required.'];
        }

        if ($values === []) {
            return [false, 'At least one value is required.'];
        }

        $options = $this->addAttributeOptions($user);

        if (! array_key_exists($attribute, $options)) {
            return [false, 'Attribute is not available for Add. It may already exist, be protected, or not allowed by the current objectClass set.'];
        }

        $meta = $this->attributeMetadataForUser($user, $attribute);

        [$syntaxValid, $syntaxMessage] = $this->validateValuesAgainstSyntax($attribute, $values, $meta);

        if (! $syntaxValid) {
            return [false, $syntaxMessage];
        }

        return [true, null];
    }

    public function validateReplace(LdapUserEntry $user, string $attribute, array $values): array
    {
        $attribute = trim($attribute);

        if ($attribute === '') {
            return [false, 'Attribute is required.'];
        }

        if ($values === []) {
            return [false, 'At least one value is required.'];
        }

        $options = $this->replaceAttributeOptions($user);

        if (! array_key_exists($attribute, $options)) {
            return [false, 'Attribute is not available for Replace. It may not exist or is protected.'];
        }

        $meta = $this->attributeMetadataForUser($user, $attribute);

        [$syntaxValid, $syntaxMessage] = $this->validateValuesAgainstSyntax($attribute, $values, $meta);

        if (! $syntaxValid) {
            return [false, $syntaxMessage];
        }

        return [true, null];
    }

    public function validateRemove(LdapUserEntry $user, string $attribute): array
    {
        $attribute = trim($attribute);

        if ($attribute === '') {
            return [false, 'Attribute is required.'];
        }

        $options = $this->removeAttributeOptions($user);

        if (! array_key_exists($attribute, $options)) {
            return [false, 'Attribute is not available for Remove. It may be required by objectClass, protected, or not present.'];
        }

        return [true, null];
    }


    public function directAddObjectClassOptions(LdapUserEntry $user): array
    {
        $schema = $this->schemaForUser($user);
        $existing = collect($this->objectClasses($user))
            ->mapWithKeys(fn (string $name): array => [strtolower($name) => true])
            ->all();

        $options = [];

        foreach ($schema['object_classes'] ?? [] as $key => $meta) {
            $name = $meta['name'] ?? $key;
            $kind = strtoupper((string) ($meta['kind'] ?? 'UNKNOWN'));

            if (isset($existing[strtolower($name)])) {
                continue;
            }

            if ($kind !== 'AUXILIARY') {
                continue;
            }

            $missingMust = $this->missingMustAttributesForObjectClass($user, $name);

            $options[$name] = $name
                .' — AUXILIARY'
                .' — missing MUST: '.count($missingMust)
                .' — MAY: '.count($meta['may'] ?? []);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function structuralObjectClassOptionsForRebuild(LdapUserEntry $user): array
    {
        $schema = $this->schemaForUser($user);
        $existing = collect($this->objectClasses($user))
            ->mapWithKeys(fn (string $name): array => [strtolower($name) => true])
            ->all();

        $options = [];

        foreach ($schema['object_classes'] ?? [] as $key => $meta) {
            $name = $meta['name'] ?? $key;
            $kind = strtoupper((string) ($meta['kind'] ?? 'UNKNOWN'));

            if (isset($existing[strtolower($name)])) {
                continue;
            }

            if ($kind !== 'STRUCTURAL') {
                continue;
            }

            $options[$name] = $name.' — STRUCTURAL — requires Change Entry Type / Rebuild Entry';
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function missingMustInputSchemaForObjectClass(LdapUserEntry $user, string $objectClass): array
    {
        return $this->missingMustAttributesForObjectClass($user, $objectClass);
    }

    public function dependentAttributesForRemovingObjectClass(LdapUserEntry $user, string $objectClass): array
    {
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [];
        }

        $schema = $this->schemaForUser($user);
        $attributes = $this->normalAttributes($user);

        $remainingObjectClasses = collect($this->objectClasses($user))
            ->reject(fn (string $name): bool => strtolower($name) === strtolower($objectClass))
            ->values()
            ->all();

        $allowedAfterRemove = [];

        foreach ($remainingObjectClasses as $remainingObjectClass) {
            $this->mergeObjectClassAttributes($remainingObjectClass, $schema, $allowedAfterRemove, []);
        }

        $targetAllowed = [];
        $this->mergeObjectClassAttributes($objectClass, $schema, $targetAllowed, []);

        $dependent = [];

        foreach ($attributes as $attribute => $values) {
            $lower = strtolower((string) $attribute);

            if (in_array($lower, ['dn', 'objectclass'], true)) {
                continue;
            }

            if (! $this->arrayHasCaseInsensitiveKey($targetAllowed, (string) $attribute)) {
                continue;
            }

            if ($this->arrayHasCaseInsensitiveKey($allowedAfterRemove, (string) $attribute)) {
                continue;
            }

            $dependent[(string) $attribute] = $this->values($values);
        }

        ksort($dependent, SORT_NATURAL | SORT_FLAG_CASE);

        return $dependent;
    }

    private function arrayHasCaseInsensitiveKey(array $array, string $needle): bool
    {
        foreach (array_keys($array) as $key) {
            if (strtolower((string) $key) === strtolower($needle)) {
                return true;
            }
        }

        return false;
    }


    public function addObjectClassOptions(LdapUserEntry $user): array
    {
        return $this->directAddObjectClassOptions($user);
    }

    public function removeObjectClassOptions(LdapUserEntry $user): array
    {
        $schema = $this->schemaForUser($user);
        $existing = $this->objectClasses($user);

        $options = [];

        foreach ($existing as $name) {
            $lower = strtolower($name);
            $meta = $schema['object_classes'][$lower] ?? [];

            if (in_array($lower, ['top', 'person'], true)) {
                continue;
            }

            if (($meta['kind'] ?? null) === 'STRUCTURAL') {
                continue;
            }

            $options[$name] = $name.' — '.($meta['kind'] ?? 'existing objectClass');
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function validateAddObjectClass(LdapUserEntry $user, string $objectClass, array $mustValues = []): array
    {
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [false, 'ObjectClass is required.'];
        }

        $options = $this->directAddObjectClassOptions($user);

        if (! array_key_exists($objectClass, $options)) {
            $meta = $this->objectClassMetadata($user, $objectClass);
            $kind = strtoupper((string) ($meta['kind'] ?? 'UNKNOWN'));

            if ($kind === 'STRUCTURAL') {
                return [false, 'Structural objectClass cannot be added directly to an existing entry that already has another structural chain. Use Change Entry Type / Rebuild Entry instead.'];
            }

            return [false, 'ObjectClass is not available for direct Add. It may already exist, be abstract, unsupported, or not found in LDAP schema.'];
        }

        foreach ($this->missingMustAttributesForObjectClass($user, $objectClass) as $attribute => $meta) {
            $values = $this->values($mustValues[$attribute] ?? []);

            if ($values === []) {
                return [false, 'Required MUST attribute '.$attribute.' must be filled before adding '.$objectClass.'.'];
            }

            if (($meta['single_value'] ?? false) && count($values) > 1) {
                return [false, 'Required MUST attribute '.$attribute.' is SINGLE-VALUE, so only one value is allowed.'];
            }
        }

        return [true, null];
    }

    public function validateRemoveObjectClass(LdapUserEntry $user, string $objectClass): array
    {
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [false, 'ObjectClass is required.'];
        }

        $options = $this->removeObjectClassOptions($user);

        if (! array_key_exists($objectClass, $options)) {
            return [false, 'ObjectClass is not available for Remove. Structural, required, or protected objectClass cannot be removed from here.'];
        }

        return [true, null];
    }

    public function objectClassMetadata(LdapUserEntry $user, string $objectClass): array
    {
        $schema = $this->schemaForUser($user);
        $key = strtolower(trim($objectClass));

        return $schema['object_classes'][$key] ?? [
            'name' => $objectClass,
            'kind' => 'unknown',
            'must' => [],
            'may' => [],
            'sup' => [],
        ];
    }

    public function missingMustAttributesForObjectClass(LdapUserEntry $user, string $objectClass): array
    {
        $schema = $this->schemaForUser($user);
        $existing = $this->existingNormalAttributeNames($user);
        $meta = $this->objectClassMetadata($user, $objectClass);

        $must = [];

        foreach ($this->allMustAttributesForObjectClass($objectClass, $schema, []) as $attribute) {
            if (isset($existing[strtolower($attribute)])) {
                continue;
            }

            $attributeMeta = $this->attributeMetadataFromSchema($schema, $attribute);

            $must[$attributeMeta['name'] ?? $attribute] = $attributeMeta;
        }

        ksort($must, SORT_NATURAL | SORT_FLAG_CASE);

        return $must;
    }

    public function allMustAttributesForObjectClass(string $objectClass, array $schema, array $visited): array
    {
        $key = strtolower($objectClass);

        if (isset($visited[$key])) {
            return [];
        }

        $visited[$key] = true;

        $objectClassMeta = $schema['object_classes'][$key] ?? null;

        if (! $objectClassMeta) {
            return [];
        }

        $must = [];

        foreach ($objectClassMeta['sup'] ?? [] as $parent) {
            $must = array_merge($must, $this->allMustAttributesForObjectClass($parent, $schema, $visited));
        }

        $must = array_merge($must, $objectClassMeta['must'] ?? []);

        return array_values(array_unique($must));
    }

    public function allowedAttributeMetadataForUser(LdapUserEntry $user): array
    {
        $schema = $this->schemaForUser($user);
        $objectClasses = $this->objectClasses($user);

        $allowed = [];

        foreach ($objectClasses as $objectClass) {
            $this->mergeObjectClassAttributes($objectClass, $schema, $allowed, []);
        }

        ksort($allowed, SORT_NATURAL | SORT_FLAG_CASE);

        return $allowed;
    }

    private function mergeObjectClassAttributes(string $objectClass, array $schema, array &$allowed, array $visited): void
    {
        $key = strtolower($objectClass);

        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        $objectClassMeta = $schema['object_classes'][$key] ?? null;

        if (! $objectClassMeta) {
            return;
        }

        foreach ($objectClassMeta['sup'] ?? [] as $parent) {
            $this->mergeObjectClassAttributes($parent, $schema, $allowed, $visited);
        }

        foreach (['must' => true, 'may' => false] as $bucket => $required) {
            foreach ($objectClassMeta[$bucket] ?? [] as $attributeName) {
                $attributeMeta = $this->attributeMetadataFromSchema($schema, $attributeName);
                $canonicalName = $attributeMeta['name'] ?? $attributeName;

                $allowed[$canonicalName] ??= [
                    'name' => $canonicalName,
                    'single_value' => (bool) ($attributeMeta['single_value'] ?? false),
                    'required' => false,
                    'source_object_classes' => [],
                    'syntax_oid' => $attributeMeta['syntax_oid'] ?? null,
                    'value_type' => $attributeMeta['value_type'] ?? 'unknown',
                    'equality' => $attributeMeta['equality'] ?? null,
                    'source' => 'ldap_schema',
                ];

                if ($required) {
                    $allowed[$canonicalName]['required'] = true;
                }

                $allowed[$canonicalName]['source_object_classes'][] = $objectClass;
                $allowed[$canonicalName]['source_object_classes'] = array_values(array_unique($allowed[$canonicalName]['source_object_classes']));
            }
        }
    }

    private function attributeMetadataFromSchema(array $schema, string $attributeName): array
    {
        $key = strtolower($attributeName);

        return $schema['attribute_types'][$key] ?? [
            'name' => $attributeName,
            'single_value' => false,
            'syntax_oid' => null,
            'value_type' => 'unknown',
            'equality' => null,
        ];
    }

    public function schemaForUser(LdapUserEntry $user): array
    {
        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        if (! $connection) {
            return [
                'object_classes' => [],
                'attribute_types' => [],
            ];
        }

        return Cache::remember(
            'ldap_schema_catalog_connection_'.$connection->id,
            now()->addHours(6),
            fn (): array => $this->fetchSchema($connection)
        );
    }


    public function clearSchemaCache(LdapUserEntry $user): void
    {
        if (! $user->ldap_connection_id) {
            return;
        }

        Cache::forget('ldap_schema_catalog_connection_'.$user->ldap_connection_id);
    }


    public function schemaForConnectionId(int $connectionId): array
    {
        $connection = LdapConnection::query()->find($connectionId);

        if (! $connection) {
            return [
                'object_classes' => [],
                'attribute_types' => [],
            ];
        }

        return Cache::remember(
            'ldap_schema_catalog_connection_'.$connection->id,
            now()->addHours(6),
            fn (): array => $this->fetchSchema($connection)
        );
    }

    public function structuralObjectClassOptionsForConnection(int $connectionId): array
    {
        $schema = $this->schemaForConnectionId($connectionId);
        $options = [];

        foreach ($schema['object_classes'] ?? [] as $key => $meta) {
            $name = $meta['name'] ?? $key;
            $kind = strtoupper((string) ($meta['kind'] ?? 'UNKNOWN'));

            if ($kind !== 'STRUCTURAL') {
                continue;
            }

            if (in_array(strtolower($name), ['top'], true)) {
                continue;
            }

            $must = $this->allMustAttributesForObjectClass($name, $schema, []);
            $may = $meta['may'] ?? [];

            $options[$name] = $name.' — STRUCTURAL — MUST: '.count($must).' — MAY: '.count($may);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function auxiliaryObjectClassOptionsForConnection(int $connectionId): array
    {
        $schema = $this->schemaForConnectionId($connectionId);
        $options = [];

        foreach ($schema['object_classes'] ?? [] as $key => $meta) {
            $name = $meta['name'] ?? $key;
            $kind = strtoupper((string) ($meta['kind'] ?? 'UNKNOWN'));

            if ($kind !== 'AUXILIARY') {
                continue;
            }

            $must = $this->allMustAttributesForObjectClass($name, $schema, []);
            $may = $meta['may'] ?? [];

            $options[$name] = $name.' — AUXILIARY — MUST: '.count($must).' — MAY: '.count($may);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function mustAttributesForObjectClassOnConnection(int $connectionId, string $objectClass): array
    {
        $schema = $this->schemaForConnectionId($connectionId);
        $must = [];

        foreach ($this->allMustAttributesForObjectClass($objectClass, $schema, []) as $attribute) {
            $meta = $this->attributeMetadataFromSchema($schema, $attribute);
            $must[$meta['name'] ?? $attribute] = $meta;
        }

        ksort($must, SORT_NATURAL | SORT_FLAG_CASE);

        return $must;
    }

    private function fetchSchema(LdapConnection $connection): array
    {
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
            'cn=subschema',
            '-s',
            'base',
            '(objectClass=*)',
            'objectClasses',
            'attributeTypes',
        ];

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                return [
                    'object_classes' => [],
                    'attribute_types' => [],
                ];
            }

            return $this->parseSchemaOutput($process->getOutput());
        } catch (Throwable) {
            return [
                'object_classes' => [],
                'attribute_types' => [],
            ];
        }
    }

    private function parseSchemaOutput(string $output): array
    {
        $objectClasses = [];
        $attributeTypes = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'objectClasses:')) {
                $definition = trim(substr($line, strlen('objectClasses:')));
                $parsed = $this->parseObjectClassDefinition($definition);

                if ($parsed !== null) {
                    $objectClasses[strtolower($parsed['name'])] = $parsed;
                }
            }

            if (str_starts_with($line, 'attributeTypes:')) {
                $definition = trim(substr($line, strlen('attributeTypes:')));
                $parsed = $this->parseAttributeTypeDefinition($definition);

                if ($parsed !== null) {
                    $attributeTypes[strtolower($parsed['name'])] = $parsed;
                }
            }
        }

        return [
            'object_classes' => $objectClasses,
            'attribute_types' => $attributeTypes,
        ];
    }

    private function parseObjectClassDefinition(string $definition): ?array
    {
        $name = $this->extractName($definition);

        if (! $name) {
            return null;
        }

        return [
            'name' => $name,
            'sup' => $this->extractTokenListAfterKeyword($definition, 'SUP'),
            'must' => $this->extractTokenListAfterKeyword($definition, 'MUST'),
            'may' => $this->extractTokenListAfterKeyword($definition, 'MAY'),
            'kind' => $this->containsWord($definition, 'AUXILIARY')
                ? 'AUXILIARY'
                : ($this->containsWord($definition, 'STRUCTURAL') ? 'STRUCTURAL' : 'ABSTRACT'),
            'raw' => $definition,
        ];
    }

    private function parseAttributeTypeDefinition(string $definition): ?array
    {
        $name = $this->extractName($definition);

        if (! $name) {
            return null;
        }

        $syntaxOid = $this->extractSyntaxOid($definition);

        return [
            'name' => $name,
            'single_value' => $this->containsWord($definition, 'SINGLE-VALUE'),
            'syntax_oid' => $syntaxOid,
            'value_type' => $this->syntaxLabel($syntaxOid),
            'equality' => $this->extractKeywordValue($definition, 'EQUALITY'),
            'ordering' => $this->extractKeywordValue($definition, 'ORDERING'),
            'substr' => $this->extractKeywordValue($definition, 'SUBSTR'),
            'raw' => $definition,
        ];
    }

    private function extractName(string $definition): ?string
    {
        if (preg_match("/NAME\\s+'([^']+)'/i", $definition, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match("/NAME\\s+\\(\\s*'([^']+)'/i", $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractSyntaxOid(string $definition): ?string
    {
        if (preg_match('/\\bSYNTAX\\s+([0-9.]+)/i', $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractKeywordValue(string $definition, string $keyword): ?string
    {
        if (preg_match('/\\b'.preg_quote($keyword, '/').'\\b\\s+([a-zA-Z0-9._-]+)/i', $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractTokenListAfterKeyword(string $definition, string $keyword): array
    {
        $pattern = '/\b'.preg_quote($keyword, '/').'\b\s+(\([^)]+\)|[a-zA-Z0-9._-]+)/i';

        if (! preg_match($pattern, $definition, $matches)) {
            return [];
        }

        $raw = trim($matches[1]);
        $raw = trim($raw, '() ');
        $raw = str_replace('$', ' ', $raw);
        $raw = str_replace("'", ' ', $raw);

        return collect(preg_split('/\s+/', $raw) ?: [])
            ->map(fn ($item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function containsWord(string $definition, string $word): bool
    {
        return (bool) preg_match('/\b'.preg_quote($word, '/').'\b/i', $definition);
    }

    private function attributeLabel(string $name, array $meta): string
    {
        $type = ($meta['single_value'] ?? false) ? 'single-value' : 'multi-value';
        $required = ($meta['required'] ?? false) ? 'required' : 'optional';
        $valueType = $meta['value_type'] ?? 'unknown';
        $sources = implode(', ', array_slice($meta['source_object_classes'] ?? [], 0, 3));

        return trim($name.' — '.$valueType.' — '.$type.' — '.$required.($sources ? ' — '.$sources : ''));
    }
public function syntaxLabel(?string $oid): string
    {
        return match ($oid) {
            '1.3.6.1.4.1.1466.115.121.1.1' => 'ACI item',
            '1.3.6.1.4.1.1466.115.121.1.4' => 'audio / binary',
            '1.3.6.1.4.1.1466.115.121.1.5' => 'binary',
            '1.3.6.1.4.1.1466.115.121.1.6' => 'bit string',
            '1.3.6.1.4.1.1466.115.121.1.7' => 'boolean',
            '1.3.6.1.4.1.1466.115.121.1.8' => 'certificate / binary',
            '1.3.6.1.4.1.1466.115.121.1.9' => 'certificate list / binary',
            '1.3.6.1.4.1.1466.115.121.1.10' => 'certificate pair / binary',
            '1.3.6.1.4.1.1466.115.121.1.11' => 'country code',
            '1.3.6.1.4.1.1466.115.121.1.12' => 'distinguished name / DN',
            '1.3.6.1.4.1.1466.115.121.1.15' => 'text / directory string',
            '1.3.6.1.4.1.1466.115.121.1.21' => 'delivery method',
            '1.3.6.1.4.1.1466.115.121.1.22' => 'guide',
            '1.3.6.1.4.1.1466.115.121.1.23' => 'UTC time',
            '1.3.6.1.4.1.1466.115.121.1.24' => 'datetime / generalized time',
            '1.3.6.1.4.1.1466.115.121.1.26' => 'email / ASCII string',
            '1.3.6.1.4.1.1466.115.121.1.27' => 'integer / number',
            '1.3.6.1.4.1.1466.115.121.1.28' => 'JPEG / binary image',
            '1.3.6.1.4.1.1466.115.121.1.34' => 'name and optional UID',
            '1.3.6.1.4.1.1466.115.121.1.36' => 'numeric string',
            '1.3.6.1.4.1.1466.115.121.1.38' => 'OID',
            '1.3.6.1.4.1.1466.115.121.1.39' => 'other mailbox',
            '1.3.6.1.4.1.1466.115.121.1.40' => 'octet string / binary',
            '1.3.6.1.4.1.1466.115.121.1.41' => 'postal address',
            '1.3.6.1.4.1.1466.115.121.1.44' => 'printable string',
            '1.3.6.1.4.1.1466.115.121.1.50' => 'telephone number',
            '1.3.6.1.4.1.1466.115.121.1.53' => 'UTC time',
            default => $oid ? 'advanced syntax '.$oid : 'unknown',
        };
    }


    public function isManuallyEditableAttributeMeta(array $meta): bool
    {
        $oid = (string) ($meta['syntax_oid'] ?? '');
        $name = strtolower((string) ($meta['name'] ?? ''));

        if ($this->isProtectedAttribute($name)) {
            return false;
        }

        if (str_contains($name, 'certificate') || str_contains($name, 'pkcs') || str_contains($name, 'smime')) {
            return false;
        }

        return in_array($oid, [
            '1.3.6.1.4.1.1466.115.121.1.7',
            '1.3.6.1.4.1.1466.115.121.1.11',
            '1.3.6.1.4.1.1466.115.121.1.15',
            '1.3.6.1.4.1.1466.115.121.1.23',
            '1.3.6.1.4.1.1466.115.121.1.24',
            '1.3.6.1.4.1.1466.115.121.1.26',
            '1.3.6.1.4.1.1466.115.121.1.27',
            '1.3.6.1.4.1.1466.115.121.1.36',
            '1.3.6.1.4.1.1466.115.121.1.41',
            '1.3.6.1.4.1.1466.115.121.1.44',
            '1.3.6.1.4.1.1466.115.121.1.50',
        ], true);
    }

    public function validateValuesAgainstSyntax(string $attribute, array $values, array $meta): array
    {
        $oid = (string) ($meta['syntax_oid'] ?? '');

        if (($meta['single_value'] ?? false) && count($values) > 1) {
            return [false, 'Attribute '.$attribute.' is SINGLE-VALUE. Only one value is allowed.'];
        }

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                return [false, 'Attribute '.$attribute.' contains an empty value.'];
            }

            if ($oid === '1.3.6.1.4.1.1466.115.121.1.27' && ! preg_match('/^-?[0-9]+$/', $value)) {
                return [false, 'Attribute '.$attribute.' must be an integer / number.'];
            }

            if ($oid === '1.3.6.1.4.1.1466.115.121.1.7' && ! in_array(strtolower($value), ['true', 'false'], true)) {
                return [false, 'Attribute '.$attribute.' must be boolean: TRUE or FALSE.'];
            }

            if ($oid === '1.3.6.1.4.1.1466.115.121.1.36' && ! preg_match('/^[0-9 ]+$/', $value)) {
                return [false, 'Attribute '.$attribute.' must be numeric string. Use numbers only.'];
            }

            if ($oid === '1.3.6.1.4.1.1466.115.121.1.24' && ! preg_match('/^[0-9]{14}Z$/', $value)) {
                return [false, 'Attribute '.$attribute.' must use generalized time format, example: 20260508120000Z.'];
            }

            if ($oid === '1.3.6.1.4.1.1466.115.121.1.12' && ! str_contains($value, '=')) {
                return [false, 'Attribute '.$attribute.' must be a valid DN, example: cn=group,ou=groups,dc=example,dc=com.'];
            }
        }

        return [true, null];
    }


    private function objectClasses(LdapUserEntry $user): array
    {
        return $this->values($user->object_classes ?? []);
    }

    private function existingNormalAttributeNames(LdapUserEntry $user): array
    {
        $names = [];

        foreach (array_keys($this->normalAttributes($user)) as $name) {
            $names[strtolower($name)] = true;
        }

        return $names;
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

    private function findCaseInsensitiveKey(array $array, string $needle): ?string
    {
        foreach (array_keys($array) as $key) {
            if (strtolower((string) $key) === strtolower($needle)) {
                return (string) $key;
            }
        }

        return null;
    }

    private function isProtectedAttribute(string $name): bool
    {
        return in_array(strtolower($name), [
            'dn',
            'entrydn',
            'entryuuid',
            'entrycsn',
            'createtimestamp',
            'modifytimestamp',
            'creatorsname',
            'modifiersname',
            'memberof',
            'objectclass',
            'userpassword',
            'pwdhistory',
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
}
