<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Support\Directory\LdapSchemaDefinitionParser;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class SyncLdapSchemaEntriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public ?int $ldapConnectionId = null,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        $executionId = $this->commandExecutionId;

        try {
            $connections = $this->connections();

            $summary = [
                'connections' => $connections->count(),
                'seen' => 0,
                'updated' => 0,
                'errors' => [],
            ];

            foreach ($connections as $connection) {
                $result = $this->syncConnection($connection);

                $summary['seen'] += $result['seen'];
                $summary['updated'] += $result['updated'];

                if ($result['error']) {
                    $summary['errors'][] = [
                        'connection_id' => $connection->id,
                        'connection_name' => $connection->name ?? $connection->id,
                        'error' => $result['error'],
                    ];
                }
            }

            if ($executionId && class_exists(SafeCommandExecutionLogger::class)) {
                SafeCommandExecutionLogger::markSuccess($executionId, [
                    'message' => 'LDAP schema sync completed.',
                    'summary' => $summary,
                ]);
            }
        } catch (Throwable $e) {
            if ($executionId && class_exists(SafeCommandExecutionLogger::class)) {
                SafeCommandExecutionLogger::markFailed($executionId, $e->getMessage(), [
                    'exception' => get_class($e),
                ]);
            }

            throw $e;
        }
    }

    private function connections()
    {
        $query = LdapConnection::query();

        if ($this->ldapConnectionId) {
            return $query->whereKey($this->ldapConnectionId)->get();
        }

        $query->where(function (Builder $query): void {
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

        if ($connections->isEmpty()) {
            return LdapConnection::query()->get();
        }

        return $connections;
    }

    private function syncConnection($connection): array
    {
        $seen = 0;
        $updated = 0;
        $error = null;

        try {
            $uri = $this->ldapUri($connection);
            $bindDn = $this->value($connection, ['schema_bind_dn', 'config_bind_dn', 'bind_dn', 'username']);
            $password = $this->value($connection, ['schema_bind_password', 'config_bind_password', 'bind_password', 'password']);
            $baseDn = $this->value($connection, ['subschema_dn', 'schema_read_dn'], 'cn=Subschema');

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
                'attributeTypes',
                'objectClasses',
                'ldapSyntaxes',
                'matchingRules',
                'matchingRuleUse',
            ]);

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $this->logRawCommand($connection, $command, $stdout, $stderr, $process->getExitCode());

            if (! $process->isSuccessful()) {
                return [
                    'seen' => 0,
                    'updated' => 0,
                    'error' => trim($stderr) ?: 'ldapsearch failed with exit code '.$process->getExitCode(),
                ];
            }

            $parsed = $this->parseLdifSchemaOutput($stdout);

            $now = Carbon::now();

            foreach ($parsed as $entry) {
                $schemaType = $entry['schema_type'];
                $definition = $entry['definition'];
                $sourceAttribute = $entry['source_attribute'];
                $valueIndex = $entry['value_index'];

                $meta = LdapSchemaDefinitionParser::parse($schemaType, $definition);

                $row = [
                    'ldap_connection_id' => $connection->id,
                    'schema_type' => $schemaType,
                    'primary_name' => $meta['primary_name'] ?? null,
                    'display_name' => $meta['display_name'] ?? null,
                    'names' => json_encode($meta['names'] ?? []),
                    'oid' => $meta['oid'] ?? null,
                    'kind' => $meta['kind'] ?? null,
                    'superior' => $meta['superior'] ?? null,
                    'syntax_oid' => $meta['syntax_oid'] ?? null,
                    'syntax_description' => $meta['syntax_description'] ?? null,
                    'equality_rule' => $meta['equality_rule'] ?? null,
                    'ordering_rule' => $meta['ordering_rule'] ?? null,
                    'substring_rule' => $meta['substring_rule'] ?? null,
                    'is_single_value' => (bool) ($meta['is_single_value'] ?? false),
                    'is_operational' => (bool) ($meta['is_operational'] ?? false),
                    'is_obsolete' => (bool) ($meta['is_obsolete'] ?? false),
                    'must_attributes' => json_encode($meta['must_attributes'] ?? []),
                    'may_attributes' => json_encode($meta['may_attributes'] ?? []),
                    'applies_to_attributes' => json_encode($meta['applies_to_attributes'] ?? []),
                    'raw_definition' => $meta['raw_definition'] ?? $definition,
                    'source_dn' => $baseDn,
                    'value_index' => $valueIndex,
                    'definition_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
                    'status' => 'active',
                    'last_seen_at' => $now,
                    'last_synced_at' => $now,
                    'description' => $meta['description'] ?? null,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('ldap_schema_entries', 'type')) {
                    $row['type'] = $schemaType;
                }

                if (Schema::hasColumn('ldap_schema_entries', 'name')) {
                    $row['name'] = $meta['primary_name'] ?? $meta['oid'] ?? null;
                }

                if (Schema::hasColumn('ldap_schema_entries', 'raw')) {
                    $row['raw'] = $meta['raw_definition'] ?? $definition;
                }

                $identifier = [
                    'ldap_connection_id' => $connection->id,
                    'schema_type' => $schemaType,
                    'oid' => $meta['oid'] ?? null,
                ];

                if (! $identifier['oid']) {
                    $identifier = [
                        'ldap_connection_id' => $connection->id,
                        'schema_type' => $schemaType,
                        'primary_name' => $meta['primary_name'] ?? sha1($definition),
                    ];
                }

                $exists = DB::table('ldap_schema_entries')->where($identifier)->exists();

                if ($exists) {
                    DB::table('ldap_schema_entries')->where($identifier)->update($this->onlyExistingColumns($row));
                } else {
                    $row['created_at'] = $now;
                    DB::table('ldap_schema_entries')->insert($this->onlyExistingColumns($row));
                }

                $seen++;
                $updated++;
            }

            DB::table('ldap_schema_entries')
                ->where('ldap_connection_id', $connection->id)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '<', $now)
                ->whereIn('schema_type', [
                    'attribute_type',
                    'object_class',
                    'ldap_syntax',
                    'matching_rule',
                    'matching_rule_use',
                ])
                ->update([
                    'status' => 'missing_from_ldap',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'seen' => $seen,
            'updated' => $updated,
            'error' => $error,
        ];
    }

    private function parseLdifSchemaOutput(string $output): array
    {
        $rows = [];
        $index = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim($line);

            if ($line === '' || str_starts_with($line, 'dn:')) {
                continue;
            }

            foreach ([
                'attributeTypes',
                'objectClasses',
                'ldapSyntaxes',
                'matchingRules',
                'matchingRuleUse',
            ] as $attribute) {
                if (str_starts_with($line, $attribute.':')) {
                    $definition = trim(substr($line, strlen($attribute) + 1));
                    $schemaType = LdapSchemaDefinitionParser::ldapAttributeToSchemaType($attribute);

                    if (! $schemaType) {
                        continue;
                    }

                    $index[$attribute] = ($index[$attribute] ?? 0) + 1;

                    $rows[] = [
                        'source_attribute' => $attribute,
                        'schema_type' => $schemaType,
                        'definition' => $definition,
                        'value_index' => $index[$attribute],
                    ];
                }
            }
        }

        return $rows;
    }

    private function logRawCommand($connection, array $command, string $stdout, string $stderr, ?int $exitCode): void
    {
        try {
            SafeCommandExecutionLogger::createFinished(
                'ldap_schema_sync_raw',
                $this->redactedCommand($command),
                $stdout,
                $stderr,
                $exitCode ?? 0,
                [
                    'ldap_connection_id' => $connection->id ?? null,
                    'ldap_connection_name' => $connection->name ?? null,
                ]
            );
        } catch (Throwable) {
            // Do not fail sync because logging helper version differs.
        }
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

    private function ldapUri($connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if (str_contains($host, '://')) {
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

    private function onlyExistingColumns(array $row): array
    {
        return collect($row)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn('ldap_schema_entries', $column))
            ->toArray();
    }
}
