<?php

namespace App\Support\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapEntryTypeRule;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RootOuEntryTypeRegistrySyncService
{
    public const AUTO_MARKER = '[AUTO_OU_NAV]';

    public function sync(?int $connectionId = null, ?string $rootDn = null, ?int $commandExecutionId = null): array
    {
        $summary = [
            'ok' => false,
            'connection_id' => $connectionId,
            'root_dn' => $rootDn,
            'found_ous' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'items' => [],
        ];

        try {
            $connection = $this->resolveConnection($connectionId);

            if (! $connection) {
                throw new \RuntimeException('LDAP connection not found.');
            }

            $rootDn = $rootDn ?: $this->baseDn($connection);

            if (! $rootDn) {
                throw new \RuntimeException('Root/base DN is empty. Configure base_dn in LDAP Connection.');
            }

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
                $rootDn,
                '(objectClass=organizationalUnit)',
                'dn',
                'ou',
                'objectClass',
            ];

            $displayCommand = 'ldapsearch -LLL -x -o ldif-wrap=no'
                .' -H '.$this->ldapUri($connection)
                .' -D '.$this->bindDn($connection)
                .' -w [REDACTED]'
                .' -b '.$rootDn
                .' "(objectClass=organizationalUnit)" dn ou objectClass';

            $childExecution = SafeCommandExecutionLogger::createQueued(
                'ldap_root_ou_registry_discovery',
                $displayCommand,
                [
                    'operation' => 'discover_root_ou_for_entry_type_registry',
                    'connection_id' => $connection->id ?? null,
                    'root_dn' => $rootDn,
                    'parent_command_execution_id' => $commandExecutionId,
                ]
            );

            $process = new Process($command, base_path());
            $process->setTimeout(300);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            if (! $process->isSuccessful()) {
                $message = trim($stderr ?: $stdout ?: 'ldapsearch failed.');

                SafeCommandExecutionLogger::markFailed(
                    SafeCommandExecutionLogger::id($childExecution),
                    $message,
                    [
                        'stdout' => $this->redact($stdout),
                        'stderr' => $this->redact($stderr),
                        'exit_code' => $process->getExitCode(),
                    ]
                );

                throw new \RuntimeException($message);
            }

            SafeCommandExecutionLogger::markSuccess(
                SafeCommandExecutionLogger::id($childExecution),
                [
                    'stdout' => $this->redact($stdout),
                    'stderr' => $this->redact($stderr),
                    'exit_code' => $process->getExitCode(),
                ]
            );

            $ous = $this->parseLdifEntries($stdout);

            $summary['found_ous'] = count($ous);

            foreach ($ous as $entry) {
                try {
                    $dn = $entry['dn'] ?? null;
                    $ou = $entry['ou'] ?? null;

                    if (! $dn || ! $ou) {
                        $summary['skipped']++;

                        continue;
                    }

                    $result = $this->upsertOuRule(
                        connection: $connection,
                        rootDn: $rootDn,
                        dn: $dn,
                        ou: $ou,
                    );

                    $summary[$result['created'] ? 'created' : 'updated']++;

                    $summary['items'][] = $result;
                } catch (Throwable $e) {
                    $summary['errors'][] = $e->getMessage();
                }
            }

            $summary['ok'] = count($summary['errors']) === 0;

            return $summary;
        } catch (Throwable $e) {
            $summary['ok'] = false;
            $summary['errors'][] = $e->getMessage();

            Log::error('Root OU Entry Type Registry sync failed', [
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            return $summary;
        }
    }

    public function upsertOuRule(LdapConnection $connection, string $rootDn, string $dn, string $ou): array
    {
        if (! class_exists(LdapEntryTypeRule::class)) {
            throw new \RuntimeException('LdapEntryTypeRule model not found.');
        }

        $model = new LdapEntryTypeRule();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            throw new \RuntimeException('Entry Type Registry table not found: '.$table);
        }

        $columns = Schema::getColumnListing($table);

        $depth = $this->ouDepth($dn);
        $pathKey = $this->ouPathKey($dn);
        $menuLabel = $this->labelFromOu($ou);

        $ruleKey = $pathKey;

        $existing = $this->findExistingRule($ruleKey, $dn, $columns);

        $payload = $this->payloadForRule(
            columns: $columns,
            connection: $connection,
            rootDn: $rootDn,
            dn: $dn,
            ou: $ou,
            ruleKey: $ruleKey,
            label: $menuLabel,
            depth: $depth,
        );

        $created = false;

        if ($existing) {
            $existing->forceFill($payload)->save();
            $record = $existing;
        } else {
            $record = new LdapEntryTypeRule();
            $record->forceFill($payload)->save();
            $created = true;
        }

        return [
            'created' => $created,
            'id' => $record->id ?? null,
            'rule_key' => $ruleKey,
            'label' => $menuLabel,
            'dn' => $dn,
            'ou' => $ou,
            'depth' => $depth,
        ];
    }

    private function payloadForRule(
        array $columns,
        LdapConnection $connection,
        string $rootDn,
        string $dn,
        string $ou,
        string $ruleKey,
        string $label,
        int $depth,
    ): array {
        $payload = [];

        $this->setFirst($payload, $columns, ['rule_key', 'key', 'slug', 'code'], $ruleKey);
        $this->setFirst($payload, $columns, ['name', 'label', 'display_name', 'navigation_label'], $label);
        $this->setFirst($payload, $columns, ['entry_type', 'type'], 'dynamic_ou');
        $this->setFirst($payload, $columns, ['entry_category', 'category'], 'dynamic_directory');
        $this->setFirst($payload, $columns, ['identifier_attribute', 'identifier'], 'dn');
        $this->setFirst($payload, $columns, ['display_attribute', 'display'], 'cn');
        $this->setFirst($payload, $columns, ['base_dn', 'parent_dn', 'search_base_dn', 'default_base_dn'], $dn);
        $this->setFirst($payload, $columns, ['ldap_filter', 'filter', 'search_filter'], '(objectClass=*)');
        $this->setFirst($payload, $columns, ['priority', 'navigation_sort', 'sort', 'sort_order'], 200 + $depth);
        $this->setFirst($payload, $columns, ['enabled', 'is_enabled', 'active', 'is_active'], true);
        $this->setFirst($payload, $columns, ['system_rule', 'is_system', 'system'], false);
        $this->setFirst($payload, $columns, ['ldap_connection_id', 'connection_id'], $connection->id ?? null);

        $description = self::AUTO_MARKER
            .' Auto-created from LDAP OU. DN: '.$dn
            .' | Root DN: '.$rootDn
            .' | Connection ID: '.($connection->id ?? 'N/A');

        $this->setFirst($payload, $columns, ['description', 'notes'], $description);

        $this->setFirst($payload, $columns, ['required_object_classes'], ['organizationalUnit']);
        $this->setFirst($payload, $columns, ['optional_object_classes'], ['top']);
        $this->setFirst($payload, $columns, ['dn_contains_patterns'], [$dn]);
        $this->setFirst($payload, $columns, ['dn_starts_with_patterns'], ['ou=', 'cn=', 'uid=']);
        $this->setFirst($payload, $columns, ['object_class', 'primary_object_class', 'structural_object_class'], 'organizationalUnit');

        if (in_array('metadata', $columns, true)) {
            $payload['metadata'] = [
                'auto_marker' => self::AUTO_MARKER,
                'source' => 'root_ou_auto_registry',
                'root_dn' => $rootDn,
                'ou_dn' => $dn,
                'ou' => $ou,
                'depth' => $depth,
                'connection_id' => $connection->id ?? null,
            ];
        }

        return $payload;
    }

    private function findExistingRule(string $ruleKey, string $dn, array $columns): ?LdapEntryTypeRule
    {
        $query = LdapEntryTypeRule::query();

        $query->where(function ($query) use ($ruleKey, $dn, $columns): void {
            foreach (['rule_key', 'key', 'slug', 'code'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, $ruleKey);
                }
            }

            foreach (['base_dn', 'parent_dn', 'search_base_dn', 'default_base_dn'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, $dn);
                }
            }

            foreach (['description', 'notes'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, 'like', '%'.$dn.'%');
                }
            }
        });

        return $query->first();
    }

    private function setFirst(array &$payload, array $columns, array $candidates, mixed $value): void
    {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $payload[$column] = $value;

                return;
            }
        }
    }

    private function parseLdifEntries(string $ldif): array
    {
        $entries = [];
        $current = [];

        foreach (preg_split('/\R/', $ldif) as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === 'ou' && isset($current['ou'])) {
                $current['ou_values'][] = $value;
                continue;
            }

            $current[$key] = $value;
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return collect($entries)
            ->filter(fn (array $entry): bool => isset($entry['dn']) && isset($entry['ou']))
            ->values()
            ->all();
    }

    private function ouDepth(string $dn): int
    {
        preg_match_all('/(^|,)ou=/i', $dn, $matches);

        return count($matches[0]);
    }

    private function ouPathKey(string $dn): string
    {
        preg_match_all('/ou=([^,]+)/i', $dn, $matches);

        $parts = $matches[1] ?? [];

        $parts = array_reverse($parts);

        return collect($parts)
            ->map(fn (string $part): string => Str::slug($part, '_'))
            ->filter()
            ->implode('_');
    }

    private function labelFromOu(string $ou): string
    {
        return Str::of($ou)
            ->replace(['-', '_'], ' ')
            ->headline()
            ->toString();
    }

    private function resolveConnection(?int $connectionId): ?LdapConnection
    {
        if ($connectionId) {
            return LdapConnection::query()->find($connectionId);
        }

        $model = new LdapConnection();
        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);

        $query = LdapConnection::query();

        foreach (['is_default', 'default', 'is_active', 'active', 'enabled', 'is_enabled'] as $column) {
            if (in_array($column, $columns, true)) {
                $candidate = (clone $query)->where($column, true)->orderBy('id')->first();

                if ($candidate) {
                    return $candidate;
                }
            }
        }

        return $query->orderBy('id')->first();
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? $connection->url ?? '127.0.0.1';
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

    private function baseDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->base_dn
            ?? $connection->root_dn
            ?? $connection->default_base_dn
            ?? 'dc=petra,dc=ac,dc=id'
        );
    }

    private function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(-w\s+)(\S+)/', '$1[REDACTED]', $text);
    }
}
