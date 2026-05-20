<?php

namespace App\Console\Commands;

use App\Models\Directory\LdapConnection;
use App\Support\Directory\LdapSchemaDefinitionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class IamSyncLdapSchemaDirectCommand extends Command
{
    protected $signature = 'iam:schema-sync-direct 
        {--connection= : LDAP connection ID or name. Empty means all active connections}
        {--reset=0 : Reset schema rows for selected connection before sync}';

    protected $description = 'Directly sync LDAP schema entries from cn=Subschema including attributeTypes, objectClasses, ldapSyntaxes, matchingRules, and matchingRuleUse.';

    public function handle(): int
    {
        $connections = $this->connections();

        if ($connections->isEmpty()) {
            $this->error('No LDAP connections found.');
            return self::FAILURE;
        }

        $grandTotal = 0;

        foreach ($connections as $connection) {
            $this->line('');
            $this->info('Syncing schema from LDAP connection: '.$connection->id.' - '.$connection->name);

            try {
                if ((string) $this->option('reset') === '1') {
                    DB::table('ldap_schema_entries')
                        ->where('ldap_connection_id', $connection->id)
                        ->delete();

                    $this->warn('Old schema rows deleted for connection ID '.$connection->id);
                }

                $output = $this->ldapsearch($connection);
                $rows = $this->parseLdifSchemaOutput($output);

                $this->line('Parsed rows: '.count($rows));

                $counts = [];

                foreach ($rows as $row) {
                    $this->storeRow($connection, $row);
                    $counts[$row['schema_type']] = ($counts[$row['schema_type']] ?? 0) + 1;
                    $grandTotal++;
                }

                foreach ($counts as $type => $count) {
                    $this->line(' - '.$type.': '.$count);
                }
            } catch (Throwable $e) {
                $this->error('Failed connection '.$connection->name.': '.$e->getMessage());
                report($e);
            }
        }

        $this->line('');
        $this->info('Schema direct sync finished. Total stored/updated: '.$grandTotal);

        $this->line('');
        $this->info('Current DB counts:');

        $dbCounts = DB::table('ldap_schema_entries')
            ->select('schema_type', DB::raw('count(*) as total'))
            ->groupBy('schema_type')
            ->orderBy('schema_type')
            ->get();

        foreach ($dbCounts as $item) {
            $this->line(' - '.$item->schema_type.': '.$item->total);
        }

        return self::SUCCESS;
    }

    private function connections()
    {
        $value = $this->option('connection');

        $query = LdapConnection::query();

        if ($value !== null && $value !== '') {
            return $query
                ->where('id', $value)
                ->orWhere('name', $value)
                ->get();
        }

        $query->where(function ($query): void {
            if (Schema::hasColumn('ldap_connections', 'is_active')) {
                $query->orWhere('is_active', true);
            }

            if (Schema::hasColumn('ldap_connections', 'active')) {
                $query->orWhere('active', true);
            }

            if (Schema::hasColumn('ldap_connections', 'enabled')) {
                $query->orWhere('enabled', true);
            }
        });

        $connections = $query->get();

        return $connections->isNotEmpty()
            ? $connections
            : LdapConnection::query()->get();
    }

    private function ldapsearch($connection): string
    {
        $uri = $this->ldapUri($connection);

        $bindDn = $this->value($connection, [
            'bind_dn',
            'schema_bind_dn',
            'config_bind_dn',
            'username',
        ]);

        $password = $this->value($connection, [
            'bind_password',
            'password',
            'schema_bind_password',
            'config_bind_password',
        ]);

        $baseDn = $this->value($connection, [
            'subschema_dn',
            'schema_read_dn',
        ], 'cn=Subschema');

        $command = [
            'ldapsearch',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-x',
            '-H',
            $uri,
        ];

        if ($bindDn !== '') {
            $command[] = '-D';
            $command[] = $bindDn;
        }

        if ($password !== '') {
            $command[] = '-w';
            $command[] = $password;
        }

        $command = array_merge($command, [
            '-b',
            $baseDn,
            '-s',
            'base',
            '(objectClass=*)',
            'ldapSyntaxes',
            'matchingRules',
            'matchingRuleUse',
            'attributeTypes',
            'objectClasses',
            '+',
        ]);

        $this->line('Running: '.$this->redactedCommand($command));

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ldapsearch failed with exit code '.$process->getExitCode());
        }

        return $process->getOutput();
    }

    private function parseLdifSchemaOutput(string $output): array
    {
        $output = $this->unfoldLdif($output);

        $rows = [];
        $index = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim((string) $line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'dn:')) {
                continue;
            }

            foreach ([
                'attributeTypes',
                'objectClasses',
                'ldapSyntaxes',
                'matchingRules',
                'matchingRuleUse',
                'olcAttributeTypes',
                'olcObjectClasses',
                'olcLdapSyntaxes',
                'olcMatchingRules',
                'olcMatchingRuleUse',
            ] as $ldapAttribute) {
                if (! str_starts_with($line, $ldapAttribute.':')) {
                    continue;
                }

                $schemaType = LdapSchemaDefinitionParser::ldapAttributeToSchemaType($ldapAttribute);

                if (! $schemaType) {
                    continue;
                }

                $definition = trim(substr($line, strlen($ldapAttribute) + 1));

                if ($definition === '') {
                    continue;
                }

                $index[$ldapAttribute] = ($index[$ldapAttribute] ?? 0) + 1;

                $rows[] = [
                    'source_attribute' => $ldapAttribute,
                    'schema_type' => $schemaType,
                    'definition' => $definition,
                    'value_index' => $index[$ldapAttribute],
                ];
            }
        }

        return $rows;
    }

    private function unfoldLdif(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') && $result !== []) {
                $result[count($result) - 1] .= substr($line, 1);
                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function storeRow($connection, array $row): void
    {
        $schemaType = $row['schema_type'];
        $definition = $row['definition'];

        $meta = LdapSchemaDefinitionParser::parse($schemaType, $definition);

        $now = Carbon::now();

        $dbRow = [
            'ldap_connection_id' => $connection->id,
            'uuid' => (string) Str::uuid(),
            'schema_type' => $schemaType,
            'type' => $schemaType,
            'primary_name' => $meta['primary_name'] ?? null,
            'name' => $meta['primary_name'] ?? null,
            'display_name' => $meta['display_name'] ?? null,
            'names' => $this->json($meta['names'] ?? []),
            'oid' => $meta['oid'] ?? null,
            'kind' => $meta['kind'] ?? null,
            'superior' => $meta['superior'] ?? null,
            'syntax_oid' => $meta['syntax_oid'] ?? null,
            'syntax_description' => $meta['syntax_description'] ?? null,
            'equality_rule' => $meta['equality_rule'] ?? null,
            'ordering_rule' => $meta['ordering_rule'] ?? null,
            'substring_rule' => $meta['substring_rule'] ?? null,
            'substr_rule' => $meta['substring_rule'] ?? null,
            'is_single_value' => (bool) ($meta['is_single_value'] ?? false),
            'is_operational' => (bool) ($meta['is_operational'] ?? false),
            'is_obsolete' => (bool) ($meta['is_obsolete'] ?? false),
            'must_attributes' => $this->json($meta['must_attributes'] ?? []),
            'may_attributes' => $this->json($meta['may_attributes'] ?? []),
            'applies_to_attributes' => $this->json($meta['applies_to_attributes'] ?? []),
            'raw_definition' => $meta['raw_definition'] ?? $definition,
            'raw' => $meta['raw_definition'] ?? $definition,
            'source' => $row['source_attribute'] ?? null,
            'source_dn' => 'cn=Subschema',
            'value_index' => $row['value_index'] ?? null,
            'source_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
            'definition_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
            'status' => 'active',
            'description' => $meta['description'] ?? null,
            'last_seen_at' => $now,
            'last_synced_at' => $now,
            'updated_at' => $now,
        ];

        $dbRow = $this->onlyExistingColumns($dbRow);

        $identifier = [
            'ldap_connection_id' => $connection->id,
            'schema_type' => $schemaType,
            'oid' => $meta['oid'] ?? null,
        ];

        if (! ($identifier['oid'] ?? null)) {
            $identifier = [
                'ldap_connection_id' => $connection->id,
                'schema_type' => $schemaType,
                'definition_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
            ];
        }

        $identifier = $this->onlyExistingColumns($identifier);

        $existing = DB::table('ldap_schema_entries')->where($identifier)->first();

        if ($existing) {
            unset($dbRow['uuid']);

            DB::table('ldap_schema_entries')
                ->where('id', $existing->id)
                ->update($dbRow);

            return;
        }

        if (! isset($dbRow['uuid']) || $dbRow['uuid'] === '') {
            $dbRow['uuid'] = (string) Str::uuid();
        }

        $dbRow['created_at'] = $now;

        DB::table('ldap_schema_entries')->insert($this->onlyExistingColumns($dbRow));
    }

    private function ldapUri($connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if ($host !== '' && str_contains($host, '://')) {
            return $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function value($model, array $columns, string $default = ''): string
    {
        foreach ($columns as $column) {
            if (isset($model->{$column}) && $model->{$column} !== null && $model->{$column} !== '') {
                return (string) $model->{$column};
            }
        }

        return $default;
    }

    private function json(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function onlyExistingColumns(array $row): array
    {
        return collect($row)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn('ldap_schema_entries', $column))
            ->toArray();
    }

    private function redactedCommand(array $command): string
    {
        $copy = $command;

        foreach ($copy as $i => $part) {
            if ($part === '-w' && isset($copy[$i + 1])) {
                $copy[$i + 1] = '[REDACTED]';
            }
        }

        return implode(' ', array_map('escapeshellarg', $copy));
    }
}
