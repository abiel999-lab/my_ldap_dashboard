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

        if ($objectClasses !== []) {
            $attributes['objectClass'] = $objectClasses;
        }

        [$normalAttributes, $operationalAttributes] = $this->splitOperationalAttributes($attributes);

        $dn = $this->firstValue($data, [
            'dn',
            'entry_dn',
            'target_dn',
            'distinguished_name',
            'distinguishedName',
        ]) ?: $this->firstAttributeValue($attributes, ['dn']);

        return [
            'overview' => [
                'dn' => $dn,
                'rdn' => $this->extractRdn($dn),
                'parent_dn' => $this->extractParentDn($dn),
                'connection' => (string) (
                    $this->firstValue($data, [
                        'connection_name',
                        'ldap_connection_name',
                        'source_connection_name',
                    ])
                    ?: $this->firstValue($data, [
                        'ldap_connection_id',
                        'connection_id',
                    ])
                    ?: 'N/A'
                ),
                'status' => (string) ($this->firstValue($data, ['status']) ?: 'N/A'),
                'uid' => $this->firstAttributeValue($attributes, ['uid']),
                'cn' => $this->firstAttributeValue($attributes, ['cn']),
                'sn' => $this->firstAttributeValue($attributes, ['sn']),
                'given_name' => $this->firstAttributeValue($attributes, ['givenName', 'given_name']),
                'display_name' => $this->firstAttributeValue($attributes, ['displayName']),
                'mail' => $this->firstAttributeValue($attributes, ['mail', 'email']),
                'ou' => $this->firstAttributeValue($attributes, ['ou']),
                'description' => $this->firstAttributeValue($attributes, ['description']),
            ],

            'summary' => [
                'object_class_count' => count($objectClasses),
                'normal_attribute_count' => count($normalAttributes),
                'operational_attribute_count' => count($operationalAttributes),
                'normal_value_count' => $this->countValues($normalAttributes),
                'operational_value_count' => $this->countValues($operationalAttributes),
                'membership_count' => count($this->normalizeValues($operationalAttributes['memberOf'] ?? [])),
            ],

            'object_classes' => collect($objectClasses)
                ->values()
                ->map(fn (string $value, int $index): array => [
                    'no' => $index + 1,
                    'name' => $value,
                ])
                ->all(),

            'directory_attributes' => $this->mapAttributes($normalAttributes, false),
            'operational_attributes' => $this->mapAttributes($operationalAttributes, true),

            'memberships' => collect($this->normalizeValues($operationalAttributes['memberOf'] ?? []))
                ->values()
                ->map(fn (string $dn, int $index): array => [
                    'no' => $index + 1,
                    'dn' => $dn,
                    'cn' => $this->extractCnFromDn($dn),
                ])
                ->all(),
        ];
    }

    public function extractAttributes(Model|array $entry): array
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        foreach ([
            'attributes',
            'ldap_attributes',
            'raw_attributes',
            'entry_attributes',
            'all_attributes',
            'payload',
            'raw_data',
            'metadata',
        ] as $key) {
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
            if ($this->isSystemColumn((string) $key)) {
                continue;
            }

            if (is_scalar($value) || is_array($value) || $value === null) {
                $attributes[(string) $key] = $value;
            }
        }

        return $this->normalizeAttributeArray($attributes);
    }

    private function extractObjectClasses(array $data, array $attributes): array
    {
        $value = $data['object_classes']
            ?? $data['object_class']
            ?? $data['objectClass']
            ?? $data['objectclass']
            ?? $attributes['objectClass']
            ?? $attributes['objectclass']
            ?? null;

        return $this->normalizeObjectClasses($value);
    }

    private function splitOperationalAttributes(array $attributes): array
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

    private function isOperationalAttribute(string $name): bool
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

    private function mapAttributes(array $attributes, bool $operational): array
    {
        $rows = [];
        $number = 1;

        foreach ($attributes as $name => $values) {
            $normalized = $this->normalizeValues($values);

            $rows[] = [
                'no' => $number++,
                'name' => (string) $name,
                'value_count' => count($normalized),
                'values' => $normalized,
                'type' => $operational
                    ? 'Read Only'
                    : (count($normalized) > 1 ? 'Multi Value' : 'Single Value'),
            ];
        }

        return $rows;
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
            return collect($value)
                ->flatten()
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeValues($decoded);
            }
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
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

        return collect($value)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

    private function countValues(array $attributes): int
    {
        $count = 0;

        foreach ($attributes as $values) {
            $count += count($this->normalizeValues($values));
        }

        return $count;
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

    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function extractRdn(string $dn): string
    {
        if ($dn === 'N/A' || ! str_contains($dn, ',')) {
            return $dn;
        }

        return trim(explode(',', $dn, 2)[0]);
    }

    private function extractParentDn(string $dn): string
    {
        if ($dn === 'N/A' || ! str_contains($dn, ',')) {
            return 'N/A';
        }

        return trim(explode(',', $dn, 2)[1]);
    }

    private function extractCnFromDn(string $dn): string
    {
        if (preg_match('/(?:^|,)cn=([^,]+)/i', $dn, $matches)) {
            return $matches[1];
        }

        return $dn;
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

        return Str::endsWith($lower, ['_id', '_at']);
    }
}
