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
        $summary = [
            'connections' => 0,
            'seen' => 0,
            'updated' => 0,
            'by_type' => [],
            'errors' => [],
        ];

        try {
            $connections = $this->connections();

            $summary['connections'] = $connections->count();

            foreach ($connections as $connection) {
                $result = $this->syncConnection($connection);

                $summary['seen'] += $result['seen'];
                $summary['updated'] += $result['updated'];

                foreach (($result['by_type'] ?? []) as $type => $count) {
                    $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + $count;
                }

                if ($result['error']) {
                    $summary['errors'][] = [
                        'ldap_connection_id' => $connection->id,
                        'ldap_connection_name' => $connection->name ?? $connection->id,
                        'error' => $result['error'],
                    ];
                }
            }

            if ($this->commandExecutionId) {
                $this->markSuccess($this->commandExecutionId, [
                    'message' => 'LDAP schema sync completed.',
                    'summary' => $summary,
                ]);
            }
        } catch (Throwable $e) {
            if ($this->commandExecutionId) {
                $this->markFailed($this->commandExecutionId, $e->getMessage(), [
                    'exception' => get_class($e),
                    'summary' => $summary,
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
        $byType = [];

        try {
            $uri = $this->ldapUri($connection);
            $bindDn = $this->value($connection, ['schema_bind_dn', 'config_bind_dn', 'bind_dn', 'username']);
            $password = $this->value($connection, ['schema_bind_password', 'config_bind_password', 'bind_password', 'password']);
            $schemaReadDn = $this->value($connection, ['subschema_dn', 'schema_read_dn'], 'cn=Subschema');

            $attributes = [
                'attributeTypes',
                'objectClasses',
                'ldapSyntaxes',
                'matchingRules',
                'matchingRuleUse',
                '+',
            ];

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
                $schemaReadDn,
                '-s',
                'base',
                '(objectClass=*)',
            ], $attributes);

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? 0;

            $this->logRawCommand($connection, $command, $stdout, $stderr, $exitCode);

            if (! $process->isSuccessful()) {
                return [
                    'seen' => 0,
                    'updated' => 0,
                    'by_type' => [],
                    'error' => trim($stderr) ?: 'ldapsearch failed with exit code '.$exitCode,
                ];
            }

            $parsedRows = $this->parseLdifSchemaOutput($stdout);
            $now = Carbon::now();

            foreach ($parsedRows as $entry) {
                $schemaType = $entry['schema_type'];
                $definition = $entry['definition'];
                $sourceAttribute = $entry['source_attribute'];
                $valueIndex = $entry['value_index'];

                $meta = LdapSchemaDefinitionParser::parse($schemaType, $definition);

                $row = [
                    'ldap_connection_id' => $connection->id,
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
                    'source' => $sourceAttribute,
                    'source_dn' => $schemaReadDn,
                    'value_index' => $valueIndex,
                    'source_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
                    'definition_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
                    'status' => 'active',
                    'description' => $meta['description'] ?? null,
                    'last_seen_at' => $now,
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                ];

                $identifier = [
                    'ldap_connection_id' => $connection->id,
                    'schema_type' => $schemaType,
                    'oid' => $meta['oid'] ?? null,
                ];

                if (! $identifier['oid']) {
                    $identifier = [
                        'ldap_connection_id' => $connection->id,
                        'schema_type' => $schemaType,
                        'definition_hash' => $meta['definition_hash'] ?? sha1($schemaType.'|'.$definition),
                    ];
                }

                $existing = DB::table('ldap_schema_entries')->where($this->onlyExistingColumns($identifier))->first();

                if ($existing) {
                    DB::table('ldap_schema_entries')
                        ->where('id', $existing->id)
                        ->update($this->onlyExistingColumns($row));
                } else {
                    $row['created_at'] = $now;
                    DB::table('ldap_schema_entries')->insert($this->onlyExistingColumns($row));
                }

                $seen++;
                $updated++;
                $byType[$schemaType] = ($byType[$schemaType] ?? 0) + 1;
            }

            if (Schema::hasColumn('ldap_schema_entries', 'status')) {
                DB::table('ldap_schema_entries')
                    ->where('ldap_connection_id', $connection->id)
                    ->whereIn('schema_type', [
                        'attribute_type',
                        'object_class',
                        'ldap_syntax',
                        'matching_rule',
                        'matching_rule_use',
                    ])
                    ->where(function ($query) use ($now): void {
                        $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now);
                    })
                    ->update([
                        'status' => 'missing_from_ldap',
                        'updated_at' => Carbon::now(),
                    ]);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'seen' => $seen,
            'updated' => $updated,
            'by_type' => $byType,
            'error' => $error,
        ];
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
            ] as $attribute) {
                if (! str_starts_with($line, $attribute.':')) {
                    continue;
                }

                $schemaType = LdapSchemaDefinitionParser::ldapAttributeToSchemaType($attribute);

                if (! $schemaType) {
                    continue;
                }

                $definition = trim(substr($line, strlen($attribute) + 1));

                if ($definition === '') {
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

    private function logRawCommand($connection, array $command, string $stdout, string $stderr, int $exitCode): void
    {
        try {
            if (method_exists(SafeCommandExecutionLogger::class, 'createFinished')) {
                SafeCommandExecutionLogger::createFinished(
                    'ldap_schema_sync_raw',
                    $this->redactedCommand($command),
                    $stdout,
                    $stderr,
                    $exitCode,
                    [
                        'ldap_connection_id' => $connection->id ?? null,
                        'ldap_connection_name' => $connection->name ?? null,
                    ]
                );
            }
        } catch (Throwable) {
            // Logging must never break sync.
        }
    }

    private function markSuccess(int $id, array $payload): void
    {
        try {
            SafeCommandExecutionLogger::markSuccess($id, $payload);
        } catch (Throwable) {
            // Ignore logger version mismatch.
        }
    }

    private function markFailed(int $id, string $message, array $payload): void
    {
        try {
            SafeCommandExecutionLogger::markFailed($id, $message, $payload);
        } catch (Throwable) {
            // Ignore logger version mismatch.
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
}
