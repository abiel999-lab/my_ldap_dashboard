<?php

namespace App\Support\Directory;

use App\Models\Directory\LdapConnection;
use Illuminate\Support\Collection;
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
                $name = (string) ($item['name'] ?? '');

                if ($name === '') {
                    return [];
                }

                $kind = strtoupper((string) ($item['kind'] ?? 'UNKNOWN'));
                $must = collect($item['must'] ?? [])
                    ->map(fn ($value): string => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all();

                $may = collect($item['may'] ?? [])
                    ->map(fn ($value): string => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all();

                $label = $name.' — '.$kind;
                $label .= ' — MUST: '.($must ? implode(', ', $must) : 'none');
                $label .= ' — MAY: '.count($may);

                return [$name => $label];
            })
            ->toArray();
    }

    public function structuralOptions(?int $connectionId = null): array
    {
        $options = $this->objectClassOptions($connectionId, 'STRUCTURAL');

        if ($options !== []) {
            return $options;
        }

        return $this->fallbackObjectClasses()
            ->filter(fn (array $item): bool => ($item['kind'] ?? null) === 'STRUCTURAL')
            ->mapWithKeys(fn (array $item): array => [
                $item['name'] => $item['name'].' — '.$item['kind'].' — MUST: '.(empty($item['must']) ? 'none' : implode(', ', $item['must'])),
            ])
            ->toArray();
    }

    public function auxiliaryOptions(?int $connectionId = null): array
    {
        $options = $this->objectClassOptions($connectionId, 'AUXILIARY');

        if ($options !== []) {
            return $options;
        }

        return $this->fallbackObjectClasses()
            ->filter(fn (array $item): bool => ($item['kind'] ?? null) === 'AUXILIARY')
            ->mapWithKeys(fn (array $item): array => [
                $item['name'] => $item['name'].' — '.$item['kind'].' — MUST: '.(empty($item['must']) ? 'none' : implode(', ', $item['must'])),
            ])
            ->toArray();
    }

    public function mustAttributesForObjectClasses(?int $connectionId, array $objectClasses): array
    {
        $requested = collect($objectClasses)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($requested === []) {
            return [];
        }

        $classes = $this->objectClasses($connectionId)
            ->keyBy(fn (array $item): string => strtolower((string) ($item['name'] ?? '')));

        $must = [];

        foreach ($requested as $className) {
            $item = $classes->get($className);

            if (! $item) {
                continue;
            }

            foreach (($item['must'] ?? []) as $attribute) {
                $attribute = trim((string) $attribute);

                if ($attribute !== '') {
                    $must[$attribute] = $attribute;
                }
            }
        }

        ksort($must);

        return $must;
    }

    public function objectClasses(?int $connectionId = null): Collection
    {
        try {
            $connection = $this->connection($connectionId);

            $fromLdap = $this->loadFromLdap($connection);

            if ($fromLdap instanceof Collection && $fromLdap->isNotEmpty()) {
                return $fromLdap
                    ->filter(fn ($item): bool => is_array($item))
                    ->values();
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->fallbackObjectClasses();
    }

    public function clearCache(?int $connectionId = null): void
    {
        // Intentionally no-op.
        // We avoid serialized Collection cache because stale cache caused __PHP_Incomplete_Class crashes.
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
            ];

            $process = new Process($command, base_path());
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                return collect();
            }

            return $this->parseObjectClasses($process->getOutput());
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    private function parseObjectClasses(string $ldif): Collection
    {
        $items = [];

        $lines = preg_split('/\R/', $ldif);
        $records = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'objectClasses:')) {
                $records[] = trim(substr($line, strlen('objectClasses:')));
                continue;
            }

            if ($records !== [] && str_starts_with($line, ' ')) {
                $records[array_key_last($records)] .= ' '.trim($line);
            }
        }

        foreach ($records as $raw) {
            $raw = preg_replace('/\s+/', ' ', trim($raw));

            if ($raw === '') {
                continue;
            }

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

            $items[] = [
                'name' => $name,
                'kind' => $kind,
                'must' => $this->parseAttributeList($raw, 'MUST'),
                'may' => $this->parseAttributeList($raw, 'MAY'),
                'raw' => $raw,
            ];
        }

        return collect($items)
            ->filter(fn (array $item): bool => ! empty($item['name']))
            ->unique(fn (array $item): string => strtolower((string) $item['name']))
            ->sortBy([
                ['kind', 'desc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    private function parseAttributeList(string $raw, string $key): array
    {
        $content = null;

        if (preg_match('/\b'.$key.'\s+\(\s*([^)]+)\)/i', $raw, $m)) {
            $content = $m[1];
        } elseif (preg_match('/\b'.$key.'\s+([a-zA-Z0-9_-]+)/i', $raw, $m)) {
            $content = $m[1];
        }

        if ($content === null) {
            return [];
        }

        return collect(preg_split('/\s*\$\s*|\s+/', trim($content)))
            ->map(fn ($value): string => trim($value, " '\"\t\n\r\0\x0B"))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
                    $candidate = (clone $query)
                        ->where($column, true)
                        ->orderBy('id')
                        ->first();

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
