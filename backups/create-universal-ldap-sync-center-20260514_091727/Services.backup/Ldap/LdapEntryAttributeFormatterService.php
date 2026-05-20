<?php

namespace App\Services\Ldap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LdapEntryAttributeFormatterService
{
    public function formatForTextarea(Model|array $entry): string
    {
        $data = $entry instanceof Model ? $entry->toArray() : $entry;

        $dn = $this->firstValue($data, [
            'dn',
            'entry_dn',
            'target_dn',
            'distinguished_name',
            'distinguishedName',
        ]) ?: 'N/A';

        $connection = $this->firstValue($data, [
            'connection_name',
            'ldap_connection_name',
            'source_connection_name',
        ]) ?: $this->firstValue($data, [
            'ldap_connection_id',
            'connection_id',
        ]) ?: 'N/A';

        $status = $this->firstValue($data, ['status']) ?: 'N/A';

        $attributes = $this->extractAttributes($data);
        $objectClasses = $this->extractObjectClasses($data, $attributes);

        [$normalAttributes, $operationalAttributes] = $this->splitOperationalAttributes($attributes);

        $lines = [];

        $lines[] = 'LDAP ENTRY ATTRIBUTE STUDIO';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'DN         : '.$dn;
        $lines[] = 'Connection : '.$connection;
        $lines[] = 'Status     : '.$status;
        $lines[] = '';

        $lines[] = '1. OBJECT CLASSES';
        $lines[] = str_repeat('-', 70);

        if ($objectClasses === []) {
            $lines[] = 'N/A';
        } else {
            foreach ($objectClasses as $objectClass) {
                $lines[] = '- '.$objectClass;
            }
        }

        $lines[] = '';

        $lines[] = '2. NORMAL ATTRIBUTES';
        $lines[] = str_repeat('-', 70);

        if ($normalAttributes === []) {
            $lines[] = 'N/A';
        } else {
            foreach ($normalAttributes as $name => $values) {
                $lines[] = $name.':';
                foreach ($this->normalizeValues($values) as $value) {
                    $lines[] = '  - '.$value;
                }
                $lines[] = '';
            }
        }

        $lines[] = '';

        $lines[] = '3. OPERATIONAL / READ-ONLY ATTRIBUTES';
        $lines[] = str_repeat('-', 70);

        if ($operationalAttributes === []) {
            $lines[] = 'N/A';
        } else {
            foreach ($operationalAttributes as $name => $values) {
                $lines[] = $name.':';
                foreach ($this->normalizeValues($values) as $value) {
                    $lines[] = '  - '.$value;
                }
                $lines[] = '';
            }
        }

        $lines[] = '';

        $lines[] = '4. DEBUG SUMMARY';
        $lines[] = str_repeat('-', 70);
        $lines[] = 'Total normal attributes      : '.count($normalAttributes);
        $lines[] = 'Total operational attributes : '.count($operationalAttributes);
        $lines[] = 'Total objectClasses          : '.count($objectClasses);
        $lines[] = '';

        return implode(PHP_EOL, $lines);
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
                    return $this->normalizeAttributeArray($decoded);
                }

                $parsed = $this->parseTextAttributes($value);

                if ($parsed !== []) {
                    return $parsed;
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
            if ($this->isLikelyAttributeColumn($key)) {
                continue;
            }

            if (in_array($key, [
                'uid',
                'cn',
                'sn',
                'givenName',
                'given_name',
                'mail',
                'email',
                'description',
                'objectClass',
                'object_classes',
                'member',
                'uniqueMember',
                'memberUid',
                'memberOf',
                'entryUUID',
                'createTimestamp',
                'modifyTimestamp',
                'creatorsName',
                'modifiersName',
            ], true)) {
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

    public function normalizeObjectClasses(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[;,|\r\n]+/', $value) ?: [];
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

    public function splitOperationalAttributes(array $attributes): array
    {
        $normal = [];
        $operational = [];

        foreach ($attributes as $name => $values) {
            if ($this->isOperationalAttribute((string) $name)) {
                $operational[$name] = $values;
            } else {
                $normal[$name] = $values;
            }
        }

        ksort($normal);
        ksort($operational);

        return [$normal, $operational];
    }

    public function isOperationalAttribute(string $name): bool
    {
        $lower = strtolower($name);

        $operational = [
            'entryuuid',
            'entrycsn',
            'createtimestamp',
            'modifytimestamp',
            'creatorsname',
            'modifiersname',
            'subschemasubentry',
            'hasSubordinates',
            'hassubordinates',
            'structuralobjectclass',
            'contextcsn',
            'pwdchangedtime',
            'pwdaccountlockedtime',
            'pwdhistory',
            'memberof',
        ];

        return in_array($lower, array_map('strtolower', $operational), true);
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
                ->filter(fn (string $item): bool => $item !== '')
                ->values()
                ->all();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeValues($decoded);
            }
        }

        return [trim((string) $value)];
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

    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function isLikelyAttributeColumn(string $key): bool
    {
        return Str::endsWith($key, [
            '_id',
            '_at',
        ]) || in_array($key, [
            'id',
            'uuid',
            'created_at',
            'updated_at',
            'deleted_at',
            'connection_id',
            'ldap_connection_id',
            'status',
            'last_seen_at',
            'last_synced_at',
            'source_hash',
        ], true);
    }
}
