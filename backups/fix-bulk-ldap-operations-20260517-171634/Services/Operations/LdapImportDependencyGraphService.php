<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LdapImportDependencyGraphService
{
    private array $dnValuedAttributes = [
        'member',
        'uniqueMember',
        'owner',
        'seeAlso',
        'manager',
        'roleOccupant',
    ];

    public function buildForBatch(int $batchId): array
    {
        $batch = $this->findBatch($batchId);

        if ($batch === null) {
            throw new \RuntimeException('Import batch not found: '.$batchId);
        }

        $rowTable = $this->detectRowTable();

        if ($rowTable === null) {
            throw new \RuntimeException('Cannot detect import preview row table.');
        }

        $rows = $this->rowsForBatch($rowTable, $batchId);

        $ldap = $this->connectFromBatch($batch);

        $summary = [
            'batch_id' => $batchId,
            'row_table' => $rowTable,
            'total_rows' => count($rows),
            'dependency_references' => 0,
            'dependency_total' => 0,
            'dependency_existing' => 0,
            'dependency_missing' => 0,
            'dependency_unknown' => 0,
            'dependencies' => [],
        ];

        foreach ($rows as $row) {
            $rowPlan = $this->buildForRow($row, $ldap);

            $summary['dependency_references'] += count($rowPlan);
            $summary['dependency_total'] += count($rowPlan);

            foreach ($rowPlan as $dependency) {
                $dn = (string) ($dependency['dn'] ?? '');

                if ($dn !== '') {
                    $summary['dependencies'][$dn] = $dependency;
                }

                if (($dependency['exists'] ?? null) === true) {
                    $summary['dependency_existing']++;
                } elseif (($dependency['exists'] ?? null) === false) {
                    $summary['dependency_missing']++;
                } else {
                    $summary['dependency_unknown']++;
                }
            }

            $this->writeRowDependencyPlan($rowTable, $row, $rowPlan);
        }

        $uniqueDependencies = array_values($summary['dependencies']);

        $summary['unique_dependencies'] = count($uniqueDependencies);
        $summary['unique_existing'] = count(array_filter($uniqueDependencies, fn ($item) => ($item['exists'] ?? null) === true));
        $summary['unique_missing'] = count(array_filter($uniqueDependencies, fn ($item) => ($item['exists'] ?? null) === false));
        $summary['unique_unknown'] = count(array_filter($uniqueDependencies, fn ($item) => ($item['exists'] ?? null) === null));
        $summary['dependencies'] = $uniqueDependencies;

        $this->writeBatchDependencyPlan($batch, $summary);

        if (is_resource($ldap) || $ldap instanceof \LDAP\Connection) {
            @ldap_unbind($ldap);
        }

        return $summary;
    }

    public function buildForRow(object $row, mixed $ldap = null): array
    {
        $payload = $this->payloadFromRow($row);
        $plans = [];

        foreach ($this->dnValuedAttributes as $attribute) {
            if (! array_key_exists($attribute, $payload)) {
                continue;
            }

            $values = is_array($payload[$attribute])
                ? $payload[$attribute]
                : $this->splitMaybeMultiValue((string) $payload[$attribute]);

            foreach ($values as $dependencyDn) {
                $dependencyDn = trim((string) $dependencyDn);

                if (! $this->looksLikeDn($dependencyDn)) {
                    continue;
                }

                $exists = $ldap ? $this->ldapDnExists($ldap, $dependencyDn) : null;
                $placeholder = $this->placeholderEntryForDn($dependencyDn);

                $plans[] = [
                    'attribute' => $attribute,
                    'dn' => $dependencyDn,
                    'exists' => $exists,
                    'plan' => $exists === true ? 'already_exists' : 'auto_create_placeholder',
                    'reason' => $exists === true
                        ? 'Dependency DN already exists.'
                        : 'Dependency DN is missing or could not be verified. System can auto-create a safe placeholder before applying the main entry.',
                    'placeholder_entry' => $placeholder,
                    'parent_dn' => $this->parentDn($dependencyDn),
                    'rdn' => $this->firstRdn($dependencyDn),
                ];
            }
        }

        return $this->uniquePlans($plans);
    }

    private function findBatch(int $batchId): ?object
    {
        if (! Schema::hasTable('import_batches')) {
            return null;
        }

        return DB::table('import_batches')->where('id', $batchId)->first();
    }

    private function detectRowTable(): ?string
    {
        $candidates = [
            'import_batch_rows',
            'import_rows',
            'import_batch_items',
            'import_items',
            'operation_job_items',
        ];

        foreach ($candidates as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            $hasBatchColumn = in_array('import_batch_id', $columns, true)
                || in_array('batch_id', $columns, true);

            $hasPayloadColumn = count(array_intersect($columns, [
                'mapped_payload',
                'payload',
                'raw_payload',
                'metadata',
            ])) > 0;

            if ($hasBatchColumn && $hasPayloadColumn) {
                return $table;
            }
        }

        return null;
    }

    private function rowsForBatch(string $table, int $batchId): array
    {
        $columns = Schema::getColumnListing($table);
        $batchColumn = in_array('import_batch_id', $columns, true) ? 'import_batch_id' : 'batch_id';

        $query = DB::table($table)->where($batchColumn, $batchId);

        if (in_array('row_number', $columns, true)) {
            $query->orderBy('row_number');
        } elseif (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        return $query->get()->all();
    }

    private function payloadFromRow(object $row): array
    {
        foreach (['mapped_payload', 'payload', 'raw_payload'] as $field) {
            if (! property_exists($row, $field)) {
                continue;
            }

            $value = $row->{$field};

            $decoded = $this->decodeJsonish($value);

            if ($decoded !== []) {
                return $decoded;
            }
        }

        $metadata = property_exists($row, 'metadata')
            ? $this->decodeJsonish($row->metadata)
            : [];

        foreach (['mapped_payload', 'payload', 'raw_payload'] as $field) {
            if (isset($metadata[$field]) && is_array($metadata[$field])) {
                return $metadata[$field];
            }
        }

        return [];
    }

    private function writeRowDependencyPlan(string $table, object $row, array $plan): void
    {
        $columns = Schema::getColumnListing($table);

        $updates = [];

        $rowSummary = [
            'total' => count($plan),
            'missing' => count(array_filter($plan, fn ($item) => ($item['exists'] ?? null) === false)),
            'existing' => count(array_filter($plan, fn ($item) => ($item['exists'] ?? null) === true)),
            'unknown' => count(array_filter($plan, fn ($item) => ($item['exists'] ?? null) === null)),
        ];

        if (in_array('dependency_plan', $columns, true)) {
            $updates['dependency_plan'] = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (in_array('dependency_summary', $columns, true)) {
            $updates['dependency_summary'] = json_encode($rowSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (in_array('metadata', $columns, true)) {
            $metadata = property_exists($row, 'metadata')
                ? $this->decodeJsonish($row->metadata)
                : [];

            $metadata['dependency_plan'] = $plan;
            $metadata['dependency_summary'] = $rowSummary;

            $updates['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($updates === []) {
            return;
        }

        if (! property_exists($row, 'id')) {
            return;
        }

        DB::table($table)->where('id', $row->id)->update($updates);
    }

    private function writeBatchDependencyPlan(object $batch, array $summary): void
    {
        $columns = Schema::getColumnListing('import_batches');

        $updates = [];

        $batchSummary = [
            'references' => $summary['dependency_references'] ?? $summary['dependency_total'] ?? 0,
            'unique_dependencies' => $summary['unique_dependencies'] ?? 0,
            'unique_existing' => $summary['unique_existing'] ?? 0,
            'unique_missing' => $summary['unique_missing'] ?? 0,
            'unique_unknown' => $summary['unique_unknown'] ?? 0,
        ];

        if (in_array('dependency_plan', $columns, true)) {
            $updates['dependency_plan'] = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (in_array('dependency_summary', $columns, true)) {
            $updates['dependency_summary'] = json_encode($batchSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (in_array('metadata', $columns, true)) {
            $metadata = property_exists($batch, 'metadata')
                ? $this->decodeJsonish($batch->metadata)
                : [];

            $metadata['dependency_graph'] = $summary;

            $updates['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('import_batches')->where('id', $batch->id)->update($updates);
    }

    private function connectFromBatch(object $batch): mixed
    {
        $ldapConnectionId = null;

        foreach (['ldap_connection_id', 'target_ldap_connection_id', 'target_connection_id'] as $field) {
            if (property_exists($batch, $field) && filled($batch->{$field})) {
                $ldapConnectionId = (int) $batch->{$field};
                break;
            }
        }

        if (! $ldapConnectionId || ! Schema::hasTable('ldap_connections')) {
            return null;
        }

        $conn = DB::table('ldap_connections')->where('id', $ldapConnectionId)->first();

        if (! $conn) {
            return null;
        }

        $connArray = (array) $conn;

        $host = $this->firstFilled($connArray, [
            'url',
            'uri',
            'host',
            'hostname',
            'server',
        ]);

        if (! $host) {
            return null;
        }

        $useSsl = (bool) ($connArray['use_ssl'] ?? $connArray['ssl'] ?? false);
        $port = (int) ($connArray['port'] ?? ($useSsl ? 636 : 389));

        if (! str_starts_with((string) $host, 'ldap://') && ! str_starts_with((string) $host, 'ldaps://')) {
            $host = ($useSsl ? 'ldaps://' : 'ldap://').$host.':'.$port;
        }

        $bindDn = $this->firstFilled($connArray, [
            'bind_dn',
            'admin_dn',
            'username',
            'user_dn',
            'bind_user',
        ]);

        $password = $this->firstFilled($connArray, [
            'bind_password',
            'password',
            'admin_password',
            'bind_pass',
        ]);

        if (! $bindDn || $password === null) {
            return null;
        }

        $password = $this->maybeDecrypt((string) $password);

        $ldap = @ldap_connect((string) $host);

        if (! $ldap) {
            return null;
        }

        @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        $bound = @ldap_bind($ldap, (string) $bindDn, (string) $password);

        if (! $bound) {
            return null;
        }

        return $ldap;
    }

    private function ldapDnExists(mixed $ldap, string $dn): ?bool
    {
        try {
            $result = @ldap_read($ldap, $dn, '(objectClass=*)', ['dn'], 0, 1);

            if ($result === false) {
                return false;
            }

            $entries = @ldap_get_entries($ldap, $result);

            return is_array($entries) && (int) ($entries['count'] ?? 0) > 0;
        } catch (Throwable) {
            return null;
        }
    }

    private function placeholderEntryForDn(string $dn): array
    {
        [$attribute, $value] = $this->firstRdn($dn);
        $attributeLower = strtolower($attribute);

        if ($attributeLower === 'uid') {
            return [
                'objectClass' => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
                'uid' => $value,
                'cn' => $value,
                'sn' => $value,
                'displayName' => $value,
                'description' => 'Auto-created dependency for LDAP import',
            ];
        }

        if ($attributeLower === 'ou') {
            return [
                'objectClass' => ['top', 'organizationalUnit'],
                'ou' => $value,
                'description' => 'Auto-created dependency for LDAP import',
            ];
        }

        if ($attributeLower === 'cn') {
            return [
                'objectClass' => ['top', 'organizationalRole'],
                'cn' => $value,
                'description' => 'Auto-created CN dependency for LDAP import',
            ];
        }

        return [
            'objectClass' => ['top', 'extensibleObject'],
            $attribute => $value,
            'cn' => $value,
            'description' => 'Auto-created generic dependency for LDAP import',
        ];
    }

    private function parentDn(string $dn): string
    {
        $parts = $this->splitDn($dn);
        array_shift($parts);

        return implode(',', $parts);
    }

    private function firstRdn(string $dn): array
    {
        $parts = $this->splitDn($dn);
        $first = $parts[0] ?? 'cn=auto-created';

        if (! str_contains($first, '=')) {
            return ['cn', $first];
        }

        [$attribute, $value] = explode('=', $first, 2);

        return [trim($attribute), trim($value)];
    }

    private function splitDn(string $dn): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/(?<!\\\\),/', $dn) ?: []
        )));
    }

    private function splitMaybeMultiValue(string $value): array
    {
        if (str_contains($value, ';')) {
            return array_values(array_filter(array_map('trim', explode(';', $value))));
        }

        return [trim($value)];
    }

    private function looksLikeDn(string $value): bool
    {
        return str_contains($value, '=') && str_contains($value, ',');
    }

    private function uniquePlans(array $plans): array
    {
        $seen = [];
        $unique = [];

        foreach ($plans as $plan) {
            $key = strtolower((string) ($plan['attribute'] ?? '')).'|'.strtolower((string) ($plan['dn'] ?? ''));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $plan;
        }

        return $unique;
    }

    private function decodeJsonish(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function maybeDecrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }

    private function firstFilled(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && filled($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }
}
