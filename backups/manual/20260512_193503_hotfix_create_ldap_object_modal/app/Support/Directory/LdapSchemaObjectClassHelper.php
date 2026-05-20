<?php

namespace App\Support\Directory;

use App\Models\Directory\LdapConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSchemaObjectClassHelper
{
    public function objectClassOptions(?int $connectionId = null, ?string $kind = null): array
    {
        return $this->objectClasses($connectionId)
            ->filter(function (array $item) use ($kind): bool {
                if (! $kind) {
                    return true;
                }

                return strtolower((string) ($item['kind'] ?? '')) === strtolower($kind);
            })
            ->mapWithKeys(function (array $item): array {
                $name = (string) $item['name'];
                $kind = strtoupper((string) ($item['kind'] ?? 'UNKNOWN'));
                $must = implode(', ', $item['must'] ?? []);
                $mayCount = count($item['may'] ?? []);

                $label = $name.' — '.$kind;

                if ($must !== '') {
                    $label .= ' — MUST: '.$must;
                } else {
                    $label .= ' — MUST: none';
                }

                $label .= ' — MAY: '.$mayCount;

                return [$name => $label];
            })
            ->toArray();
    }

    public function structuralOptions(?int $connectionId = null): array
    {
        return $this->objectClassOptions($connectionId, 'STRUCTURAL');
    }

    public function auxiliaryOptions(?int $connectionId = null): array
    {
        return $this->objectClassOptions($connectionId, 'AUXILIARY');
    }

    public function mustAttributesForObjectClasses(?int $connectionId, array $objectClasses): array
    {
        $objectClasses = collect($objectClasses)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($objectClasses === []) {
            return [];
        }

        $classes = $this->objectClasses($connectionId)
            ->keyBy(fn (array $item): string => strtolower((string) $item['name']));

        $must = [];

        foreach ($objectClasses as $className) {
            $item = $classes->get($className);

            if (! $item) {
                continue;
            }

            foreach (($item['must'] ?? []) as $attribute) {
                $attribute = trim((string) $attribute);

                if ($attribute !== '') {
                    $must[$attribute] = $this->attributeLabel($attribute, $connectionId);
                }
            }
        }

        ksort($must);

        return $must;
    }

    public function objectClasses(?int $connectionId = null): Collection
    {
        $connection = $this->connection($connectionId);
        $cacheKey = 'ldap_schema_object_classes_v3_'.($connection?->id ?? 'default');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($connection): Collection {
            $fromLdap = $this->loadFromLdap($connection);

            if ($fromLdap->isNotEmpty()) {
                return $fromLdap;
            }

            return $this->fallbackObjectClasses();
        });
    }

    public function clearCache(?int $connectionId = null): void
    {
        $connection = $this->connection($connectionId);

        Cache::forget('ldap_schema_object_classes_v3_'.($connection?->id ?? 'default'));
    }

    private function loadFromLdap(?LdapConnection $connection): Collection
    {
        if (! $connection) {
            return collect();
        }

        try {
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

            $process = new Process($command, base_path());
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return collect();
            }

            return $this->parseObjectClasses($process->getOutput());
        } catch (Throwable) {
            return collect();
        }
    }

    private function parseObjectClasses(string $ldif): Collection
    {
        $items = [];

        preg_match_all('/objectClasses:\s*\(\s*([^\n]+(?:\n\s+[^\n]+)*)/i', $ldif, $matches);

        foreach ($matches[1] ?? [] as $raw) {
            $raw = preg_replace('/\s+/', ' ', trim($raw));

            $name = null;

            if (preg_match("/NAME\s+'([^']+)'/i", $raw, $m)) {
                $name = $m[1];
            } elseif (preg_match('/NAME\s+\(\s*\'([^\']+)\'/i', $raw, $m)) {
                $name = $m[1];
            }

            if (! $name) {
                continue;
            }

            $kind = 'ABSTRACT';

            if (stripos($raw, ' STRUCTURAL') !== false) {
                $kind = 'STRUCTURAL';
            } elseif (stripos($raw, ' AUXILIARY') !== false) {
                $kind = 'AUXILIARY';
            }

            $must = $this->parseAttributeList($raw, 'MUST');
            $may = $this->parseAttributeList($raw, 'MAY');

            $items[] = [
                'name' => $name,
                'kind' => $kind,
                'must' => $must,
                'may' => $may,
                'raw' => $raw,
            ];
        }

        return collect($items)
            ->sortBy([
                ['kind', 'desc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    private function parseAttributeList(string $raw, string $key): array
    {
        $patterns = [
            '/\b'.$key.'\s+\(\s*([^)]+)\)/i',
            '/\b'.$key.'\s+([a-zA-Z0-9_-]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $raw, $m)) {
                continue;
            }

            $content = $m[1] ?? '';

            return collect(preg_split('/\s*\$\s*|\s+/', trim($content)))
                ->map(fn ($value): string => trim($value, " '\"\t\n\r\0\x0B"))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    private function fallbackObjectClasses(): Collection
    {
        return collect([
            [
                'name' => 'top',
                'kind' => 'ABSTRACT',
                'must' => [],
                'may' => [],
            ],
            [
                'name' => 'organizationalUnit',
                'kind' => 'STRUCTURAL',
                'must' => ['ou'],
                'may' => ['description', 'seeAlso'],
            ],
            [
                'name' => 'groupOfNames',
                'kind' => 'STRUCTURAL',
                'must' => ['cn', 'member'],
                'may' => ['description', 'businessCategory', 'o', 'ou', 'owner', 'seeAlso'],
            ],
            [
                'name' => 'groupOfUniqueNames',
                'kind' => 'STRUCTURAL',
                'must' => ['cn', 'uniqueMember'],
                'may' => ['description', 'businessCategory', 'o', 'ou', 'owner', 'seeAlso'],
            ],
            [
                'name' => 'organizationalRole',
                'kind' => 'STRUCTURAL',
                'must' => ['cn'],
                'may' => ['description', 'roleOccupant', 'ou', 'seeAlso'],
            ],
            [
                'name' => 'applicationProcess',
                'kind' => 'STRUCTURAL',
                'must' => ['cn'],
                'may' => ['description', 'ou', 'seeAlso'],
            ],
            [
                'name' => 'device',
                'kind' => 'STRUCTURAL',
                'must' => ['cn'],
                'may' => ['description', 'owner', 'ou', 'seeAlso', 'serialNumber'],
            ],
            [
                'name' => 'inetOrgPerson',
                'kind' => 'STRUCTURAL',
                'must' => ['cn', 'sn'],
                'may' => ['uid', 'mail', 'givenName', 'displayName', 'description'],
            ],
            [
                'name' => 'extensibleObject',
                'kind' => 'AUXILIARY',
                'must' => [],
                'may' => [],
            ],
            [
                'name' => 'simpleSecurityObject',
                'kind' => 'AUXILIARY',
                'must' => ['userPassword'],
                'may' => [],
            ],
        ]);
    }

    private function attributeLabel(string $attribute, ?int $connectionId = null): string
    {
        return $attribute;
    }

    private function connection(?int $connectionId): ?LdapConnection
    {
        if ($connectionId) {
            return LdapConnection::query()->find($connectionId);
        }

        $query = LdapConnection::query();

        try {
            $columns = Schema::getColumnListing((new LdapConnection())->getTable());

            foreach (['is_default', 'default', 'is_active', 'active', 'enabled', 'is_enabled'] as $column) {
                if (in_array($column, $columns, true)) {
                    $candidate = (clone $query)->where($column, true)->orderBy('id')->first();

                    if ($candidate) {
                        return $candidate;
                    }
                }
            }
        } catch (Throwable) {
            //
        }

        return $query->orderBy('id')->first();
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? '127.0.0.1';
        $port = $connection->port ?? $connection->ldap_port ?? 389;
        $scheme = $connection->scheme ?? $connection->protocol ?? 'ldap';

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_dn
            ?? $connection->admin_dn
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
