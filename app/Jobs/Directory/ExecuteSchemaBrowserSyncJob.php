<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapSyncBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\OperationJobFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class ExecuteSchemaBrowserSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $operationJobId,
        public int $ldapConnectionId,
        public bool $resetBeforeSync = true,
        public ?int $ldapSyncBatchId = null,
    ) {
        $this->onQueue('ldap-schema');
    }

    public function handle(OperationJobFactory $jobs): void
    {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $connection = LdapConnection::query()->findOrFail($this->ldapConnectionId);
        $batch = $this->ldapSyncBatchId ? LdapSyncBatch::query()->find($this->ldapSyncBatchId) : null;

        $jobs->markRunning($operationJob, [
            'event' => 'schema_sync_started',
            'ldap_connection_id' => $connection->id,
            'ldap_sync_batch_id' => $batch?->id,
            'connection_name' => $connection->name ?? null,
        ]);

        if ($batch) {
            $batch->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'message' => 'Schema browser full sync is running.',
            ])->save();
        }

        try {
            $result = $this->sync($connection);

            if ($batch) {
                $batch->forceFill([
                    'status' => 'success',
                    'total_entries' => $result['total'],
                    'created_entries' => $result['created'],
                    'updated_entries' => $result['updated'],
                    'failed_entries' => 0,
                    'message' => 'Schema browser full sync completed.',
                    'finished_at' => now(),
                    'metadata' => array_merge((array) ($batch->metadata ?? []), [
                        'source_dn' => $result['source_dn'],
                        'schema_sync' => true,
                    ]),
                ])->save();
            }

            $jobs->markSuccess($operationJob, [
                'event' => 'schema_sync_success',
                'ldap_connection_id' => $connection->id,
                'ldap_sync_batch_id' => $batch?->id,
                'total_entries' => $result['total'],
                'created_entries' => $result['created'],
                'updated_entries' => $result['updated'],
                'failed_entries' => 0,
                'processed_items' => $result['total'],
                'success_items' => $result['created'] + $result['updated'],
                'failed_items' => 0,
                'source_dn' => $result['source_dn'],
            ]);
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            if ($batch) {
                $batch->forceFill([
                    'status' => 'failed',
                    'message' => $message,
                    'failed_entries' => 1,
                    'finished_at' => now(),
                ])->save();
            }

            $jobs->markFailed($operationJob, $message, [
                'event' => 'schema_sync_failed',
                'ldap_connection_id' => $connection->id ?? $this->ldapConnectionId,
                'ldap_sync_batch_id' => $batch?->id,
                'exception' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'failed_items' => 1,
            ]);

            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        $operationJob = OperationJob::query()->find($this->operationJobId);
        $batch = $this->ldapSyncBatchId ? LdapSyncBatch::query()->find($this->ldapSyncBatchId) : null;

        if ($batch) {
            $batch->forceFill([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'failed_entries' => 1,
                'finished_at' => now(),
            ])->save();
        }

        if ($operationJob) {
            app(OperationJobFactory::class)->markFailed($operationJob, $exception->getMessage(), [
                'event' => 'schema_sync_queue_failed',
                'ldap_connection_id' => $this->ldapConnectionId,
                'ldap_sync_batch_id' => $batch?->id,
                'exception' => get_class($exception),
                'failed_items' => 1,
            ]);
        }
    }

    private function sync(LdapConnection $connection): array
    {
        if (! Schema::hasTable('ldap_schema_entries')) {
            throw new \RuntimeException('Table ldap_schema_entries does not exist.');
        }

        $sourceDn = $this->discoverSubschemaDn($connection);

        $stdout = $this->ldapSearch($connection, $sourceDn, [
            'objectClasses',
            'attributeTypes',
            'matchingRules',
            'ldapSyntaxes',
        ]);

        $entry = $this->parseLdifEntry($stdout);

        $definitions = [];

        foreach ($entry['objectClasses'] ?? [] as $index => $definition) {
            $definitions[] = $this->normalizeDefinition('object_class', $definition, $sourceDn, $index);
        }

        foreach ($entry['attributeTypes'] ?? [] as $index => $definition) {
            $definitions[] = $this->normalizeDefinition('attribute_type', $definition, $sourceDn, $index);
        }

        foreach ($entry['matchingRules'] ?? [] as $index => $definition) {
            $definitions[] = $this->normalizeDefinition('matching_rule', $definition, $sourceDn, $index);
        }

        foreach ($entry['ldapSyntaxes'] ?? [] as $index => $definition) {
            $definitions[] = $this->normalizeDefinition('syntax', $definition, $sourceDn, $index);
        }

        if ($this->resetBeforeSync && Schema::hasColumn('ldap_schema_entries', 'ldap_connection_id')) {
            DB::table('ldap_schema_entries')
                ->where('ldap_connection_id', $connection->id)
                ->delete();
        }

        $created = 0;
        $updated = 0;
        $now = now();
        $columns = Schema::getColumnListing('ldap_schema_entries');

        foreach ($definitions as $definition) {
            if (($definition['oid'] ?? '') === '') {
                continue;
            }

            $where = $this->filterColumns($columns, [
                'ldap_connection_id' => $connection->id,
                'schema_type' => $definition['schema_type'],
                'oid' => $definition['oid'],
            ]);

            $exists = $where !== [] && DB::table('ldap_schema_entries')->where($where)->exists();

            $payload = $this->filterColumns($columns, [
                'ldap_connection_id' => $connection->id,
                'schema_type' => $definition['schema_type'],
                'type' => $definition['schema_type'],
                'primary_name' => $definition['primary_name'],
                'display_name' => $definition['display_name'],
                'name' => $definition['primary_name'],
                'names' => json_encode($definition['names'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'oid' => $definition['oid'],
                'kind' => $definition['kind'],
                'superior' => $definition['superior'],
                'syntax_oid' => $definition['syntax_oid'],
                'syntax_description' => $definition['syntax_description'],
                'equality_rule' => $definition['equality_rule'],
                'ordering_rule' => $definition['ordering_rule'],
                'substring_rule' => $definition['substring_rule'],
                'substr_rule' => $definition['substring_rule'],
                'is_single_value' => $definition['is_single_value'],
                'is_operational' => $definition['is_operational'],
                'is_obsolete' => $definition['is_obsolete'],
                'must_attributes' => json_encode($definition['must_attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'may_attributes' => json_encode($definition['may_attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'applies_to_attributes' => json_encode($definition['applies_to_attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'raw_definition' => $definition['raw_definition'],
                'raw' => $definition['raw_definition'],
                'source_dn' => $sourceDn,
                'source' => 'schema_browser_sync',
                'value_index' => $definition['value_index'],
                'definition_hash' => hash('sha256', $definition['raw_definition']),
                'source_hash' => hash('sha256', $definition['raw_definition']),
                'status' => 'active',
                'last_seen_at' => $now,
                'last_synced_at' => $now,
                'description' => $definition['description'],
                'updated_at' => $now,
            ]);

            if (! $exists && in_array('uuid', $columns, true)) {
                $payload['uuid'] = (string) Str::uuid();
            }

            if (! $exists && in_array('created_at', $columns, true)) {
                $payload['created_at'] = $now;
            }

            DB::table('ldap_schema_entries')->updateOrInsert($where, $payload);

            $exists ? $updated++ : $created++;
        }

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'source_dn' => $sourceDn,
        ];
    }

    private function discoverSubschemaDn(LdapConnection $connection): string
    {
        $baseDn = (string) ($connection->base_dn ?: '');

        $stdout = $this->ldapSearch($connection, $baseDn, ['subschemaSubentry'], 'base');
        $entry = $this->parseLdifEntry($stdout);
        $subschema = $entry['subschemaSubentry'][0] ?? null;

        return is_string($subschema) && trim($subschema) !== '' ? trim($subschema) : 'cn=Subschema';
    }

    private function ldapSearch(LdapConnection $connection, string $baseDn, array $attributes, string $scope = 'base'): string
    {
        $command = [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($connection),
            '-D',
            (string) $connection->bind_dn,
            '-w',
            (string) $connection->bind_password,
            '-b',
            $baseDn,
            '-s',
            $scope,
            '(objectClass=*)',
        ];

        foreach ($attributes as $attribute) {
            $command[] = $attribute;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ldapsearch failed.');
        }

        return $process->getOutput();
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ((bool) ($connection->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function parseLdifEntry(string $ldif): array
    {
        $ldif = str_replace(["\r\n", "\r"], "\n", $ldif);
        $lines = explode("\n", trim($ldif));
        $normalized = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') && $normalized !== []) {
                $normalized[count($normalized) - 1] .= substr($line, 1);
                continue;
            }

            $normalized[] = $line;
        }

        $entry = [];

        foreach ($normalized as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = ltrim($value);

            if (str_starts_with($value, ':')) {
                $value = base64_decode(trim(substr($value, 1))) ?: trim($value);
            } else {
                $value = trim($value);
            }

            if ($key === 'dn') {
                continue;
            }

            $entry[$key] ??= [];
            $entry[$key][] = $value;
        }

        return $entry;
    }

    private function normalizeDefinition(string $type, string $raw, string $sourceDn, int $index): array
    {
        $oid = $this->match('/^\(\s*([^\s]+)/', $raw);
        $names = $this->parseNames($raw);
        $primary = $names[0] ?? $oid;

        return [
            'schema_type' => $type,
            'primary_name' => $primary ?: $oid,
            'display_name' => $primary ?: $oid,
            'names' => $names,
            'oid' => $oid,
            'kind' => $this->kind($raw),
            'superior' => $this->match('/\bSUP\s+([^\s\)]+)/', $raw),
            'syntax_oid' => $this->match('/\bSYNTAX\s+([^\s\{]+)/', $raw),
            'syntax_description' => null,
            'equality_rule' => $this->match('/\bEQUALITY\s+([^\s\)]+)/', $raw),
            'ordering_rule' => $this->match('/\bORDERING\s+([^\s\)]+)/', $raw),
            'substring_rule' => $this->match('/\bSUBSTR\s+([^\s\)]+)/', $raw),
            'is_single_value' => str_contains($raw, ' SINGLE-VALUE'),
            'is_operational' => str_contains($raw, ' USAGE directoryOperation'),
            'is_obsolete' => str_contains($raw, ' OBSOLETE'),
            'must_attributes' => $this->parseAttributeGroup($raw, 'MUST'),
            'may_attributes' => $this->parseAttributeGroup($raw, 'MAY'),
            'applies_to_attributes' => [],
            'raw_definition' => $raw,
            'source_dn' => $sourceDn,
            'value_index' => $index,
            'description' => $this->match("/\\bDESC\\s+'([^']*)'/", $raw),
        ];
    }

    private function parseNames(string $raw): array
    {
        $single = $this->match("/\\bNAME\\s+'([^']+)'/", $raw);

        if ($single) {
            return [$single];
        }

        if (preg_match('/\bNAME\s+\((.*?)\)/', $raw, $matches)) {
            preg_match_all("/'([^']+)'/", $matches[1], $names);

            return collect($names[1] ?? [])->map(fn ($name): string => trim((string) $name))->filter()->values()->all();
        }

        return [];
    }

    private function parseAttributeGroup(string $raw, string $keyword): array
    {
        if (! preg_match('/\b'.$keyword.'\s+(\([^\)]*\)|[^\s\)]*)/', $raw, $matches)) {
            return [];
        }

        $value = trim($matches[1]);
        $value = trim($value, '() ');

        return collect(preg_split('/\s*\$\s*|\s+/', $value) ?: [])
            ->map(fn ($item): string => trim((string) $item, " '"))
            ->filter()
            ->values()
            ->all();
    }

    private function kind(string $raw): ?string
    {
        foreach (['STRUCTURAL', 'AUXILIARY', 'ABSTRACT'] as $kind) {
            if (str_contains($raw, ' '.$kind)) {
                return strtolower($kind);
            }
        }

        return null;
    }

    private function match(string $pattern, string $value): ?string
    {
        return preg_match($pattern, $value, $matches) ? trim((string) ($matches[1] ?? '')) : null;
    }

    private function filterColumns(array $columns, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->toArray();
    }
}
