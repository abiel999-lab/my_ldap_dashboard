<?php

namespace App\Services\Ldap;

use App\Models\Directory\LdapConnection;
use Throwable;

class LdapSchemaDropdownService
{
    public function auxiliaryObjectClassOptions(mixed $ldapConnectionId): array
    {
        $schema = $this->loadSchema($ldapConnectionId);
        $options = [];

        foreach ($schema['objectClasses'] as $objectClass) {
            if (($objectClass['kind'] ?? null) !== 'AUXILIARY') {
                continue;
            }

            $name = $objectClass['name'];

            $label = $name . ' — AUXILIARY';

            if (! empty($objectClass['must'])) {
                $label .= ' — MUST: ' . implode(', ', $objectClass['must']);
            }

            $options[$name] = $label;
        }

        ksort($options);

        return $options;
    }

    public function objectClassOptions(mixed $ldapConnectionId): array
    {
        return $this->auxiliaryObjectClassOptions($ldapConnectionId);
    }

    public function attributeOptions(mixed $ldapConnectionId, mixed $objectClassName = null): array
    {
        $schema = $this->loadSchema($ldapConnectionId);
        $attributes = [];

        if ($objectClassName) {
            $objectClass = $schema['objectClasses'][strtolower((string) $objectClassName)] ?? null;

            if ($objectClass) {
                foreach (array_merge($objectClass['must'] ?? [], $objectClass['may'] ?? []) as $attribute) {
                    $attributes[$attribute] = $attribute;
                }
            }
        }

        if ($attributes === []) {
            foreach ($schema['attributeTypes'] as $attribute) {
                $attributes[$attribute['name']] = $attribute['name'];
            }
        }

        ksort($attributes);

        return $attributes;
    }

    public function mustAttributes(mixed $ldapConnectionId, mixed $objectClassName = null): array
    {
        if (! $objectClassName) {
            return [];
        }

        $schema = $this->loadSchema($ldapConnectionId);

        return $schema['objectClasses'][strtolower((string) $objectClassName)]['must'] ?? [];
    }

    private function loadSchema(mixed $ldapConnectionId): array
    {
        if (! $ldapConnectionId) {
            return $this->fallbackSchema();
        }

        $connection = LdapConnection::query()->find($ldapConnectionId);

        if (! $connection) {
            return $this->fallbackSchema();
        }

        try {
            $ldap = $this->connect($connection);

            if (! $ldap) {
                return $this->fallbackSchema();
            }

            $schemaDn = $this->schemaDn($ldap);
            $schema = $this->readSchema($ldap, $schemaDn);

            if (! empty($schema['objectClasses'])) {
                return $schema;
            }
        } catch (Throwable) {
            return $this->fallbackSchema();
        }

        return $this->fallbackSchema();
    }

    private function connect(LdapConnection $connection): mixed
    {
        $host = trim((string) ($connection->host ?? ''));

        if ($host === '') {
            return null;
        }

        $port = (int) ($connection->port ?? 389);
        $uri = str_starts_with($host, 'ldap://') || str_starts_with($host, 'ldaps://')
            ? $host
            : 'ldap://' . $host . ':' . $port;

        $ldap = @ldap_connect($uri);

        if (! $ldap) {
            return null;
        }

        @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

        $bindDn = (string) ($connection->bind_dn ?? $connection->username ?? '');
        $bindPassword = (string) ($connection->bind_password ?? $connection->password ?? '');

        if ($bindDn !== '') {
            return @ldap_bind($ldap, $bindDn, $bindPassword) ? $ldap : null;
        }

        return @ldap_bind($ldap) ? $ldap : null;
    }

    private function schemaDn(mixed $ldap): string
    {
        $read = @ldap_read($ldap, '', '(objectClass=*)', ['subschemaSubentry']);

        if ($read) {
            $entries = @ldap_get_entries($ldap, $read);
            $dn = $entries[0]['subschemasubentry'][0] ?? null;

            if ($dn) {
                return (string) $dn;
            }
        }

        return 'cn=schema';
    }

    private function readSchema(mixed $ldap, string $schemaDn): array
    {
        $read = @ldap_read($ldap, $schemaDn, '(objectClass=*)', ['objectClasses', 'attributeTypes']);

        if (! $read) {
            $read = @ldap_read($ldap, 'cn=schema', '(objectClass=*)', ['objectClasses', 'attributeTypes']);
        }

        if (! $read) {
            return $this->fallbackSchema();
        }

        $entries = @ldap_get_entries($ldap, $read);

        if (($entries['count'] ?? 0) < 1) {
            return $this->fallbackSchema();
        }

        $row = $entries[0];

        $objectClasses = [];
        $attributeTypes = [];

        foreach ($this->entryValues($row, 'objectclasses') as $definition) {
            $names = $this->parseNames($definition);

            if ($names === []) {
                continue;
            }

            $name = $names[0];

            $objectClasses[strtolower($name)] = [
                'name' => $name,
                'kind' => $this->parseKind($definition),
                'must' => $this->parseAttributeList($definition, 'MUST'),
                'may' => $this->parseAttributeList($definition, 'MAY'),
                'raw' => $definition,
            ];
        }

        foreach ($this->entryValues($row, 'attributetypes') as $definition) {
            $names = $this->parseNames($definition);

            if ($names === []) {
                continue;
            }

            $name = $names[0];

            $attributeTypes[strtolower($name)] = [
                'name' => $name,
                'syntax' => $this->parseAttributeSyntax($definition),
                'single_value' => str_contains(strtoupper($definition), 'SINGLE-VALUE'),
                'raw' => $definition,
            ];
        }

        return [
            'objectClasses' => $objectClasses,
            'attributeTypes' => $attributeTypes,
        ];
    }

    private function entryValues(array $row, string $key): array
    {
        $values = [];
        $count = (int) ($row[$key]['count'] ?? 0);

        for ($i = 0; $i < $count; $i++) {
            if (isset($row[$key][$i])) {
                $values[] = (string) $row[$key][$i];
            }
        }

        return $values;
    }

    private function parseNames(string $definition): array
    {
        if (preg_match('/\bNAME\s+\((.*?)\)/i', $definition, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $names);

            return array_values(array_filter($names[1] ?? []));
        }

        if (preg_match("/\bNAME\s+'([^']+)'/i", $definition, $m)) {
            return [$m[1]];
        }

        return [];
    }

    private function parseKind(string $definition): string
    {
        if (preg_match('/\bAUXILIARY\b/i', $definition)) {
            return 'AUXILIARY';
        }

        if (preg_match('/\bSTRUCTURAL\b/i', $definition)) {
            return 'STRUCTURAL';
        }

        if (preg_match('/\bABSTRACT\b/i', $definition)) {
            return 'ABSTRACT';
        }

        return 'UNKNOWN';
    }

    private function parseAttributeList(string $definition, string $key): array
    {
        if (preg_match('/\b' . preg_quote($key, '/') . '\s+\((.*?)\)/i', $definition, $m)) {
            $parts = preg_split('/\s*\$\s*/', trim($m[1]));

            return array_values(array_filter(array_map(
                fn ($v) => trim($v, " \t\n\r\0\x0B'"),
                $parts
            )));
        }

        if (preg_match('/\b' . preg_quote($key, '/') . '\s+([a-zA-Z][a-zA-Z0-9;-]*)/i', $definition, $m)) {
            return [$m[1]];
        }

        return [];
    }


    public function attributeMeta(
        mixed $ldapConnectionId,
        mixed $attributeName = null
    ): array {
        if (! $attributeName) {
            return [];
        }

        $schema = $this->loadSchema($ldapConnectionId);

        $attribute = $schema['attributeTypes'][strtolower((string) $attributeName)] ?? null;

        if (! $attribute) {
            return [
                'name' => $attributeName,
                'syntax' => 'Unknown',
                'single_value' => false,
                'example' => null,
            ];
        }

        return [
            'name' => $attribute['name'] ?? $attributeName,
            'syntax' => $attribute['syntax'] ?? 'Directory String',
            'single_value' => (bool) ($attribute['single_value'] ?? false),
            'example' => $this->exampleForAttribute($attribute['name'] ?? $attributeName),
        ];
    }

    private function exampleForAttribute(string $attribute): ?string
    {
        return match (strtolower($attribute)) {
            'associateddomain' => 'alumni.petra.ac.id',
            'mail' => 'user@petra.ac.id',
            'uid' => 'usr000046',
            'uidnumber' => '1001',
            'gidnumber' => '1001',
            'homedirectory' => '/home/usr000046',
            'loginshell' => '/bin/bash',
            default => 'example-value',
        };
    }

    private function fallbackSchema(): array
    {
        return [
            'objectClasses' => [
                'posixaccount' => [
                    'name' => 'posixAccount',
                    'kind' => 'AUXILIARY',
                    'must' => ['cn', 'uid', 'uidNumber', 'gidNumber', 'homeDirectory'],
                    'may' => ['loginShell', 'gecos'],
                ],
                'shadowaccount' => [
                    'name' => 'shadowAccount',
                    'kind' => 'AUXILIARY',
                    'must' => ['uid'],
                    'may' => ['userPassword', 'shadowLastChange', 'shadowMax'],
                ],
                'extensibleobject' => [
                    'name' => 'extensibleObject',
                    'kind' => 'AUXILIARY',
                    'must' => [],
                    'may' => [],
                ],
            ],
            'attributeTypes' => [
                'cn' => ['name' => 'cn'],
                'uid' => ['name' => 'uid'],
                'uidnumber' => ['name' => 'uidNumber'],
                'gidnumber' => ['name' => 'gidNumber'],
                'homedirectory' => ['name' => 'homeDirectory'],
                'loginshell' => ['name' => 'loginShell'],
                'gecos' => ['name' => 'gecos'],
                'userpassword' => ['name' => 'userPassword'],
            ],
        ];
    }
}
