<?php

namespace App\Services\Ldap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LdapEntryInspectorService
{
    public function inspect(Model|array $entry): array
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        $normalAttributes = $this->extractNormalAttributes($data);
        $operationalAttributes = $this->extractOperationalAttributes($data);

        $objectClasses = $this->normalizeValues(
            $data['object_classes']
            ?? $normalAttributes['objectClass']
            ?? $normalAttributes['objectclass']
            ?? []
        );

        if ($objectClasses !== []) {
            $normalAttributes['objectClass'] = $objectClasses;
        }

        $groupDns = $this->normalizeValues(
            $data['group_dns']
            ?? $operationalAttributes['memberOf']
            ?? $operationalAttributes['memberof']
            ?? []
        );

        $dn = $this->firstValue(
            $data['dn']
            ?? $normalAttributes['dn']
            ?? $operationalAttributes['entryDN']
            ?? $operationalAttributes['entrydn']
            ?? null
        ) ?? 'N/A';

        return [
            'overview' => [
                'dn' => $dn,
                'rdn' => $this->extractRdn($dn),
                'parent_dn' => $this->extractParentDn($dn),

                'connection' => (string) (
                    $data['connection_name']
                    ?? $data['ldap_connection_name']
                    ?? $data['source_connection_name']
                    ?? $data['ldap_connection_id']
                    ?? $data['connection_id']
                    ?? 'N/A'
                ),

                'status' => (string) ($data['status'] ?? $this->firstValue($normalAttributes['petraAccountStatus'] ?? null) ?? 'N/A'),

                'uid' => $this->firstValue($normalAttributes['uid'] ?? null) ?? 'N/A',
                'cn' => $this->firstValue($normalAttributes['cn'] ?? null) ?? 'N/A',
                'sn' => $this->firstValue($normalAttributes['sn'] ?? null) ?? 'N/A',
                'given_name' => $this->firstValue($normalAttributes['givenName'] ?? $normalAttributes['givenname'] ?? null) ?? 'N/A',
                'display_name' => $this->firstValue($normalAttributes['displayName'] ?? $normalAttributes['displayname'] ?? null) ?? 'N/A',
                'mail' => $this->firstValue($normalAttributes['mail'] ?? null) ?? 'N/A',
                'ou' => $this->firstValue($normalAttributes['ou'] ?? null) ?? 'N/A',
                'description' => $this->firstValue($normalAttributes['description'] ?? null) ?? 'N/A',
            ],

            'summary' => [
                'object_class_count' => count($objectClasses),
                'normal_attribute_count' => count($normalAttributes),
                'operational_attribute_count' => count($operationalAttributes),
                'normal_value_count' => $this->countValues($normalAttributes),
                'operational_value_count' => $this->countValues($operationalAttributes),
                'membership_count' => count($groupDns),
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

            'memberships' => collect($groupDns)
                ->values()
                ->map(fn (string $dn, int $index): array => [
                    'no' => $index + 1,
                    'cn' => $this->extractCnFromDn($dn),
                    'dn' => $dn,
                    'category' => $this->guessMembershipCategory($dn),
                ])
                ->all(),
        ];
    }

    private function extractNormalAttributes(array $data): array
    {
        $value = $data['attributes']
            ?? $data['ldap_attributes']
            ?? $data['raw_attributes']
            ?? $data['entry_attributes']
            ?? [];

        $attributes = $this->decodeAttributePayload($value);

        if ($attributes !== []) {
            return $attributes;
        }

        $fallback = [];

        foreach ($data as $key => $value) {
            if ($this->isSystemColumn((string) $key)) {
                continue;
            }

            if ($this->isOperationalAttributeName((string) $key)) {
                continue;
            }

            if (is_scalar($value) || is_array($value) || $value === null) {
                $fallback[(string) $key] = $this->normalizeValues($value);
            }
        }

        ksort($fallback, SORT_NATURAL | SORT_FLAG_CASE);

        return $fallback;
    }

    private function extractOperationalAttributes(array $data): array
    {
        $value = $data['operational_attributes']
            ?? $data['operationalAttributes']
            ?? $data['read_only_attributes']
            ?? [];

        $attributes = $this->decodeAttributePayload($value);

        if ($attributes !== []) {
            return $attributes;
        }

        $fallback = [];

        foreach ($data as $key => $value) {
            if (! $this->isOperationalAttributeName((string) $key)) {
                continue;
            }

            $fallback[(string) $key] = $this->normalizeValues($value);
        }

        ksort($fallback, SORT_NATURAL | SORT_FLAG_CASE);

        return $fallback;
    }

    private function decodeAttributePayload(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeAttributeArray($decoded);
            }

            return $this->normalizeAttributeArray($this->parseTextAttributes($value));
        }

        if (is_array($value)) {
            return $this->normalizeAttributeArray($value);
        }

        return [];
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

    private function mapAttributes(array $attributes, bool $operational): array
    {
        $rows = [];
        $number = 1;

        foreach ($attributes as $name => $values) {
            $values = $this->normalizeValues($values);

            $rows[] = [
                'no' => $number++,
                'name' => (string) $name,
                'value_count' => count($values),
                'type' => $operational
                    ? 'Read Only'
                    : (count($values) > 1 ? 'Multi Value' : 'Single Value'),
                'values' => $this->maskSensitiveValues((string) $name, $values),
            ];
        }

        return $rows;
    }

    private function maskSensitiveValues(string $attribute, array $values): array
    {
        if (in_array(strtolower($attribute), [
            'userpassword',
            'authpassword',
            'unicodepwd',
            'pwdhistory',
        ], true)) {
            return collect($values)
                ->map(fn (): string => '[PROTECTED VALUE]')
                ->all();
        }

        return $values;
    }

    private function normalizeValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeValues($decoded);
            }

            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item): string => trim((string) $item))
                ->filter(fn (string $item): bool => $item !== '')
                ->unique()
                ->values()
                ->all();
        }

        return [trim((string) $value)];
    }

    private function firstValue(mixed $value): ?string
    {
        $values = $this->normalizeValues($value);

        return $values[0] ?? null;
    }

    private function countValues(array $attributes): int
    {
        $count = 0;

        foreach ($attributes as $values) {
            $count += count($this->normalizeValues($values));
        }

        return $count;
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

    private function isOperationalAttributeName(string $name): bool
    {
        return in_array(strtolower($name), [
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

    private function guessMembershipCategory(string $dn): string
    {
        $lower = strtolower($dn);

        if (str_contains($lower, 'ou=apps') || str_contains($lower, 'app-')) {
            return 'Application';
        }

        if (str_contains($lower, 'ou=roles') || str_contains($lower, 'role-')) {
            return 'Role';
        }

        if (str_contains($lower, 'ou=units') || str_contains($lower, 'unit')) {
            return 'Unit';
        }

        return 'Group';
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
            'entry_uuid',
            'object_classes',
            'group_dns',
            'operational_attributes',
        ], true)) {
            return true;
        }

        return Str::endsWith($lower, ['_id', '_at']);
    }
}
