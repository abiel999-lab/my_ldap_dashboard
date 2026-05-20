<?php

namespace App\Services\Ldap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LdapEntryInspectorService
{
    public function inspect(Model|array $entry): array
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        $attributes = $this->extractAttributes($data);
        $objectClasses = $this->extractObjectClasses($data, $attributes);

        if ($objectClasses !== [] && ! isset($attributes['objectClass'])) {
            $attributes['objectClass'] = $objectClasses;
        }

        [$normalAttributes, $operationalAttributes] = $this->splitOperationalAttributes($attributes);

        $dn = $this->firstValue($data, [
            'dn',
            'entry_dn',
            'target_dn',
            'distinguished_name',
            'distinguishedName',
        ]) ?: 'N/A';

        $rdn = $this->extractRdn($dn);
        $parentDn = $this->extractParentDn($dn);

        return [
            'overview' => [
                'dn' => $dn,
                'rdn' => $rdn,
                'parent_dn' => $parentDn,
                'connection' => (string) ($this->firstValue($data, [
                    'connection_name',
                    'ldap_connection_name',
                    'source_connection_name',
                ]) ?: $this->firstValue($data, [
                    'ldap_connection_id',
                    'connection_id',
                ]) ?: 'N/A'),
                'status' => (string) ($this->firstValue($data, ['status']) ?: 'N/A'),
                'uid' => $this->firstAttributeValue($attributes, ['uid']),
                'cn' => $this->firstAttributeValue($attributes, ['cn']),
                'sn' => $this->firstAttributeValue($attributes, ['sn']),
                'givenName' => $this->firstAttributeValue($attributes, ['givenName', 'given_name']),
                'displayName' => $this->firstAttributeValue($attributes, ['displayName']),
                'mail' => $this->firstAttributeValue($attributes, ['mail', 'email']),
                'ou' => $this->firstAttributeValue($attributes, ['ou']),
                'description' => $this->firstAttributeValue($attributes, ['description']),
            ],

            'stats' => [
                'object_class_count' => count($objectClasses),
                'normal_attribute_count' => count($normalAttributes),
                'operational_attribute_count' => count($operationalAttributes),
                'normal_value_count' => $this->countValues($normalAttributes),
                'operational_value_count' => $this->countValues($operationalAttributes),
            ],

            'object_classes' => array_map(
                fn (string $value, int $index): array => [
                    'no' => $index + 1,
                    'name' => $value,
                ],
                array_values($objectClasses),
                array_keys(array_values($objectClasses))
            ),

            'normal_attributes' => $this->mapAttributes($normalAttributes),
            'operational_attributes' => $this->mapAttributes($operationalAttributes),

            'raw_attributes_json' => json_encode(
                $attributes,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '{}',
        ];
    }

    public function extractAttributes(Model|array $entry): array
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        $candidateKeys = [
            'attributes',
            'ldap_attributes',
            'raw_attributes',
            'entry_attributes',
            'all_attributes',
            'payload',
            'raw_data',
            'metadata',
        ];

        foreach ($candidateKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (isset($decoded['attributes']) && is_array($decoded['attributes'])) {
                        return $this->normalizeAttributeArray($decoded['attributes']);
                    }

                    if (isset($decoded['ldap_attributes']) && is_array($decoded['ldap_attributes'])) {
                        return $this->normalizeAttributeArray($decoded['ldap_attributes']);
                    }

                    return $this->normalizeAttributeArray($decoded);
                }

                $parsed = $this->parseTextAttributes($value);

                if ($parsed !== []) {
                    return $this->normalizeAttributeArray($parsed);
                }
            }

            if (is_array($value)) {
                if (isset($value['attributes']) && is_array($value['attributes'])) {
                    return $this->normalizeAttributeArray($value['attributes']);
                }

                if (isset($value['ldap_attributes']) && is_array($value['ldap_attributes'])) {
                    return $this->normalizeAttributeArray($value['ldap_attributes']);
                }

                return $this->normalizeAttributeArray($value);
            }
        }

        $attributes = [];

        foreach ($data as $key => $value) {
            if ($this->isSystemColumn($key)) {
                continue;
            }

            if (is_array($value) || is_scalar($value) || $value === null) {
                $attributes[$key] = $value;
            }
        }

        return $this->normalizeAttributeArray($attributes);
    }

    public function extractObjectClasses(Model|array $entry, ?array $attributes = null): array
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        $value = $this->firstValue($data, [
            'object_classes',
            'object_class',
            'objectClass',
            'objectclass',
        ]);

        if ($value === null && $attributes !== null) {
            $value = $attributes['objectClass']
                ?? $attributes['objectclass']
                ?? $attributes['object_classes']
                ?? null;
        }

        return $this->normalizeObjectClasses($value);
    }

    public function splitOperationalAttributes(array $attributes): array
    {
        $normal = [];
        $operational = [];

        foreach ($attributes as $name => $values) {
            if (strtolower((string) $name) === 'objectclass') {
                $normal[$name] = $values;
                continue;
            }

            if ($this->isOperationalAttribute((string) $name)) {
                $operational[$name] = $values;
            } else {
                $normal[$name] = $values;
            }
        }

        ksort($normal, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($operational, SORT_NATURAL | SORT_FLAG_CASE);

        return [$normal, $operational];
    }

    public function isOperationalAttribute(string $name): bool
    {
        $lower = strtolower($name);

        $known = [
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
        ];

        return in_array($lower, $known, true);
    }

    private function mapAttributes(array $attributes): array
    {
        $rows = [];
        $counter = 1;

        foreach ($attributes as $name => $values) {
            $normalizedValues = $this->normalizeValues($values);

            $rows[] = [
                'no' => $counter++,
                'name' => (string) $name,
                'value_count' => count($normalizedValues),
                'is_multi' => count($normalizedValues) > 1,
                'values' => $normalizedValues,
                'joined_values' => implode(' | ', $normalizedValues),
            ];
        }

        return $rows;
    }

    private function countValues(array $attributes): int
    {
        $count = 0;

        foreach ($attributes as $values) {
            $count += count($this->normalizeValues($values));
        }

        return $count;
    }

    private function extractRdn(string $dn): string
    {
        if ($dn === 'N/A' || trim($dn) === '') {
            return 'N/A';
        }

        $parts = explode(',', $dn, 2);

        return trim($parts[0] ?? 'N/A');
    }

    private function extractParentDn(string $dn): string
    {
        if ($dn === 'N/A' || trim($dn) === '' || ! str_contains($dn, ',')) {
            return 'N/A';
        }

        $parts = explode(',', $dn, 2);

        return trim($parts[1] ?? 'N/A');
    }

    private function firstAttributeValue(array $attributes, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $values = $this->normalizeValues($attributes[$key]);

            if ($values !== []) {
                return (string) $values[0];
            }
        }

        return 'N/A';
    }

    private function normalizeAttributeArray(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (is_int($key) && is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $normalized[(string) $nestedKey] = $this->normalizeValues($nestedValue);
                }

                continue;
            }

            if (is_array($value) && array_key_exists('name', $value) && array_key_exists('value', $value)) {
                $normalized[(string) $value['name']] = $this->normalizeValues($value['value']);
                continue;
            }

            $normalized[(string) $key] = $this->normalizeValues($value);
        }

        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return $normalized;
    }

    private function normalizeValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach ($this->normalizeValues($item) as $nested) {
                        $result[] = $nested;
                    }
                    continue;
                }

                $string = trim((string) $item);

                if ($string !== '') {
                    $result[] = $string;
                }
            }

            return array_values(array_unique($result));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeValues($decoded);
            }
        }

        $string = trim((string) $value);

        return $string === '' ? [] : [$string];
    }

    private function parseTextAttributes(string $text): array
    {
        $attributes = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            $attributes[$name] ??= [];
            $attributes[$name][] = $value;
        }

        return $attributes;
    }

    private function normalizeObjectClasses(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[,\r\n;|]+/', $value) ?: [];
            }
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $result = [];

        foreach ($value as $item) {
            $string = trim((string) $item);

            if ($string !== '') {
                $result[] = $string;
            }
        }

        return array_values(array_unique($result));
    }

    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function isSystemColumn(string $key): bool
    {
        $lower = strtolower($key);

        if (in_array($lower, [
            'id',
            'uuid',
            'created_at',
            'updated_at',
            'deleted_at',
            'status',
            'last_seen_at',
            'last_synced_at',
            'connection_id',
            'ldap_connection_id',
            'source_hash',
        ], true)) {
            return true;
        }

        if (Str::endsWith($lower, ['_id', '_at'])) {
            return true;
        }

        return false;
    }
}
