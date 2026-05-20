<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LdapImportRollbackService
{
    public function rollback(int $batchId): array
    {
        $batch = DB::table('import_batches')->where('id', $batchId)->first();

        if (! $batch) {
            throw new \RuntimeException('Import batch not found: '.$batchId);
        }

        $rowTable = $this->detectRowTable($batchId);

        if (! $rowTable) {
            throw new \RuntimeException('Import rows table not found for batch '.$batchId);
        }

        $ldap = $this->connectFromBatch($batch);

        if (! $ldap) {
            throw new \RuntimeException('Cannot connect to target LDAP.');
        }

        $rows = $this->rowsForBatch($rowTable, $batchId);

        usort($rows, function ($a, $b) {
            $dnA = $this->targetDnFromRow($a);
            $dnB = $this->targetDnFromRow($b);

            return substr_count($dnB, ',') <=> substr_count($dnA, ',');
        });

        $summary = [
            'batch_id' => $batchId,
            'row_table' => $rowTable,
            'rolled_back' => 0,
            'skipped' => 0,
            'failed' => 0,
            'messages' => [],
        ];

        foreach ($rows as $row) {
            $dn = $this->targetDnFromRow($row);
            $plan = $this->planFromRow($row);
            $status = strtolower((string) ($row->status ?? ''));

            if ($dn === '') {
                $summary['skipped']++;
                $summary['messages'][] = '[SKIP] Empty DN.';
                continue;
            }

            /*
             * Safe rollback tahap pertama:
             * create/applied rows dihapus balik.
             * Update/delete rollback nanti butuh snapshot sebelum perubahan.
             */
            if (! in_array($plan, ['create', 'add'], true)) {
                $summary['skipped']++;
                $summary['messages'][] = '[SKIP] Rollback for plan '.$plan.' needs snapshot: '.$dn;
                continue;
            }

            if (! in_array($status, ['applied', 'success', 'created'], true)) {
                $summary['skipped']++;
                $summary['messages'][] = '[SKIP] Row not applied: '.$dn;
                continue;
            }

            if (! $this->ldapDnExists($ldap, $dn)) {
                $summary['skipped']++;
                $summary['messages'][] = '[SKIP] DN already missing: '.$dn;
                $this->markRowRollback($rowTable, $row, 'rollback_skipped', 'DN already missing.');
                continue;
            }

            $ok = @ldap_delete($ldap, $dn);

            if (! $ok && $this->ldapDnExists($ldap, $dn)) {
                $summary['failed']++;
                $summary['messages'][] = '[FAILED] '.$dn.' | '.$this->ldapError($ldap);
                $this->markRowRollback($rowTable, $row, 'rollback_failed', $this->ldapError($ldap));
                continue;
            }

            $summary['rolled_back']++;
            $summary['messages'][] = '[ROLLBACK] Deleted created entry: '.$dn;
            $this->markRowRollback($rowTable, $row, 'rollback_success', 'Rollback deleted created LDAP entry.');
        }

        $this->markBatchRollback($batchId, $summary);

        if ($ldap instanceof \LDAP\Connection || is_resource($ldap)) {
            @ldap_unbind($ldap);
        }

        return $summary;
    }

    private function markRowRollback(string $table, object $row, string $status, string $message): void
    {
        if (! property_exists($row, 'id')) {
            return;
        }

        $columns = Schema::getColumnListing($table);
        $updates = [];

        if (in_array('status', $columns, true)) {
            $updates['status'] = $status;
        }

        if (in_array('message', $columns, true)) {
            $updates['message'] = $message;
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table($table)->where('id', $row->id)->update($updates);
        }
    }

    private function markBatchRollback(int $batchId, array $summary): void
    {
        $columns = Schema::getColumnListing('import_batches');
        $updates = [];

        if (in_array('status', $columns, true)) {
            $updates['status'] = $summary['failed'] > 0 ? 'rollback_completed_with_issues' : 'rollback_success';
        }

        if (in_array('message', $columns, true)) {
            $updates['message'] = 'Rollback completed. Rolled back: '.$summary['rolled_back'].' | Failed: '.$summary['failed'].' | Skipped: '.$summary['skipped'];
        }

        foreach (['stdout', 'execute_stdout', 'output', 'result_output'] as $column) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = implode(PHP_EOL, $summary['messages']);
                break;
            }
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table('import_batches')->where('id', $batchId)->update($updates);
        }
    }

    private function detectRowTable(int $batchId): ?string
    {
        foreach (['import_rows', 'import_batch_rows', 'import_batch_items', 'import_items'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach (['import_batch_id', 'batch_id'] as $batchColumn) {
                if (in_array($batchColumn, $columns, true) && DB::table($table)->where($batchColumn, $batchId)->exists()) {
                    return $table;
                }
            }
        }

        return null;
    }

    private function rowsForBatch(string $table, int $batchId): array
    {
        $columns = Schema::getColumnListing($table);
        $batchColumn = in_array('import_batch_id', $columns, true) ? 'import_batch_id' : 'batch_id';

        return DB::table($table)->where($batchColumn, $batchId)->get()->all();
    }

    private function targetDnFromRow(object $row): string
    {
        if (property_exists($row, 'target_dn') && filled($row->target_dn)) {
            return (string) $row->target_dn;
        }

        $payload = $this->payloadFromRow($row);

        return (string) ($payload['dn'] ?? $payload['DN'] ?? '');
    }

    private function planFromRow(object $row): string
    {
        foreach (['action_plan', 'plan'] as $field) {
            if (property_exists($row, $field) && filled($row->{$field})) {
                return strtolower((string) $row->{$field});
            }
        }

        $payload = $this->payloadFromRow($row);

        return strtolower((string) ($payload['action'] ?? $payload['operation'] ?? $payload['changetype'] ?? 'create'));
    }

    private function payloadFromRow(object $row): array
    {
        foreach (['mapped_payload', 'payload', 'raw_payload'] as $field) {
            if (! property_exists($row, $field)) {
                continue;
            }

            $decoded = $this->decodeJsonish($row->{$field});

            if ($decoded !== []) {
                return $decoded;
            }
        }

        return [];
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

    private function ldapDnExists($ldap, string $dn): bool
    {
        $result = @ldap_read($ldap, $dn, '(objectClass=*)', ['dn'], 0, 1);

        if ($result === false) {
            return false;
        }

        $entries = @ldap_get_entries($ldap, $result);

        return is_array($entries) && (int) ($entries['count'] ?? 0) > 0;
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

        $data = (array) $conn;

        $host = $this->firstFilled($data, ['url', 'uri', 'host', 'hostname', 'server']);

        if (! $host) {
            return null;
        }

        $useSsl = (bool) ($data['use_ssl'] ?? $data['ssl'] ?? false);
        $port = (int) ($data['port'] ?? ($useSsl ? 636 : 389));

        if (! str_starts_with((string) $host, 'ldap://') && ! str_starts_with((string) $host, 'ldaps://')) {
            $host = ($useSsl ? 'ldaps://' : 'ldap://').$host.':'.$port;
        }

        $bindDn = $this->firstFilled($data, ['bind_dn', 'admin_dn', 'username', 'user_dn', 'bind_user']);
        $password = $this->firstFilled($data, ['bind_password', 'password', 'admin_password', 'bind_pass']);

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

        if (! @ldap_bind($ldap, (string) $bindDn, (string) $password)) {
            return null;
        }

        return $ldap;
    }

    private function ldapError($ldap): string
    {
        return '['.@ldap_errno($ldap).'] '.@ldap_error($ldap);
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
