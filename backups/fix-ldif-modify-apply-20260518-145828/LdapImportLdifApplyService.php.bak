<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LdapImportLdifApplyService
{
    public function apply(int $batchId): array
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

        $summary = [
            'batch_id' => $batchId,
            'row_table' => $rowTable,
            'total' => count($rows),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'add' => 0,
            'modify' => 0,
            'delete' => 0,
            'messages' => [],
        ];

        foreach ($rows as $row) {
            $ldif = trim((string) ($row->generated_ldif ?? ''));

            if ($ldif === '') {
                $summary['skipped']++;
                $summary['messages'][] = '[SKIP] Row has no generated_ldif.';
                $this->markRow($rowTable, $row, 'skipped', 'Row has no generated LDIF.');
                continue;
            }

            if (str_starts_with($ldif, '# INVALID')) {
                $summary['failed']++;
                $summary['messages'][] = '[FAILED] Row contains invalid LDIF.';
                $this->markRow($rowTable, $row, 'failed', 'Row contains invalid LDIF.');
                continue;
            }

            try {
                $operation = $this->parseLdifOperation($ldif);
                $changetype = strtolower($operation['changetype']);

                if ($changetype === 'add') {
                    $this->applyAdd($ldap, $operation);
                    $summary['add']++;
                    $summary['success']++;
                    $summary['messages'][] = '[ADD] '.$operation['dn'];
                    $this->markRow($rowTable, $row, 'applied', 'LDIF add applied.');
                    continue;
                }

                if ($changetype === 'modify') {
                    $this->applyModify($ldap, $operation);
                    $summary['modify']++;
                    $summary['success']++;
                    $summary['messages'][] = '[MODIFY] '.$operation['dn'];
                    $this->markRow($rowTable, $row, 'applied', 'LDIF modify applied.');
                    continue;
                }

                if ($changetype === 'delete') {
                    $this->applyDelete($ldap, $operation);
                    $summary['delete']++;
                    $summary['success']++;
                    $summary['messages'][] = '[DELETE] '.$operation['dn'];
                    $this->markRow($rowTable, $row, 'applied', 'LDIF delete applied.');
                    continue;
                }

                if ($changetype === 'modrdn' || $changetype === 'moddn') {
                    $this->applyModRdn($ldap, $operation);
                    $summary['modify']++;
                    $summary['success']++;
                    $summary['messages'][] = '[MODRDN] '.$operation['dn'];
                    $this->markRow($rowTable, $row, 'applied', 'LDIF modrdn applied.');
                    continue;
                }

                $summary['failed']++;
                $summary['messages'][] = '[FAILED] Unsupported changetype '.$changetype.' for '.$operation['dn'];
                $this->markRow($rowTable, $row, 'failed', 'Unsupported changetype: '.$changetype);
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['messages'][] = '[FAILED] '.$exception->getMessage();
                $this->markRow($rowTable, $row, 'failed', $exception->getMessage());
            }
        }

        $this->markBatch($batchId, $summary);

        if ($ldap instanceof \LDAP\Connection || is_resource($ldap)) {
            @ldap_unbind($ldap);
        }

        return $summary;
    }

    private function applyAdd(mixed $ldap, array $operation): void
    {
        $dn = $operation['dn'];
        $entry = $operation['entry'];

        if ($dn === '') {
            throw new \RuntimeException('LDIF add requires dn.');
        }

        if ($entry === []) {
            throw new \RuntimeException('LDIF add requires attributes for '.$dn);
        }

        if ($this->ldapDnExists($ldap, $dn)) {
            throw new \RuntimeException('Target DN already exists: '.$dn);
        }

        $ok = @ldap_add($ldap, $dn, $entry);

        if (! $ok) {
            throw new \RuntimeException($dn.' | '.$this->ldapError($ldap).' | Entry: '.$this->entryDebug($entry));
        }
    }

    private function applyModify(mixed $ldap, array $operation): void
    {
        $dn = $operation['dn'];
        $entry = $operation['entry'];

        if ($dn === '') {
            throw new \RuntimeException('LDIF modify requires dn.');
        }

        if ($entry === []) {
            throw new \RuntimeException('LDIF modify requires attributes for '.$dn);
        }

        if (! $this->ldapDnExists($ldap, $dn)) {
            throw new \RuntimeException('Target DN does not exist for modify: '.$dn);
        }

        $ok = @ldap_modify($ldap, $dn, $entry);

        if (! $ok) {
            throw new \RuntimeException($dn.' | '.$this->ldapError($ldap).' | Entry: '.$this->entryDebug($entry));
        }
    }


    private function applyModRdn(mixed $ldap, array $operation): void
    {
        $dn = $operation['dn'];
        $newRdn = trim((string) ($operation['newrdn'] ?? ''));
        $deleteOldRdn = (bool) ($operation['deleteoldrdn'] ?? true);
        $newSuperior = $operation['newsuperior'] ?? null;
        $newSuperior = is_string($newSuperior) && trim($newSuperior) !== '' ? trim($newSuperior) : null;

        if ($dn === '') {
            throw new \RuntimeException('LDIF modrdn requires dn.');
        }

        if ($newRdn === '') {
            throw new \RuntimeException('LDIF modrdn requires newrdn for '.$dn);
        }

        if (! $this->ldapDnExists($ldap, $dn)) {
            throw new \RuntimeException('Target DN does not exist for modrdn: '.$dn);
        }

        $ok = @ldap_rename($ldap, $dn, $newRdn, $newSuperior, $deleteOldRdn);

        if (! $ok) {
            throw new \RuntimeException($dn.' | '.$this->ldapError($ldap));
        }
    }

    private function applyDelete(mixed $ldap, array $operation): void
    {
        $dn = $operation['dn'];

        if ($dn === '') {
            throw new \RuntimeException('LDIF delete requires dn.');
        }

        if (! $this->ldapDnExists($ldap, $dn)) {
            throw new \RuntimeException('Target DN does not exist for delete: '.$dn);
        }

        $ok = @ldap_delete($ldap, $dn);

        if (! $ok) {
            throw new \RuntimeException($dn.' | '.$this->ldapError($ldap));
        }
    }

    private function parseLdifOperation(string $ldif): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($ldif));
        $dn = '';
        $changetype = 'add';
        $entry = [];
        $mode = null;
        $newRdn = '';
        $deleteOldRdn = true;
        $newSuperior = null;

        foreach ($lines as $rawLine) {
            $line = trim((string) $rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($line === '-') {
                $mode = null;
                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = ltrim($value);
            $lowerKey = strtolower($key);

            if ($lowerKey === 'dn') {
                $dn = $value;
                continue;
            }

            if ($lowerKey === 'changetype') {
                $changetype = strtolower($value);
                continue;
            }

            if ($changetype === 'modrdn' || $changetype === 'moddn') {
                if ($lowerKey === 'newrdn') {
                    $newRdn = $value;
                } elseif ($lowerKey === 'deleteoldrdn') {
                    $deleteOldRdn = ! in_array(strtolower($value), ['0', 'false', 'no'], true);
                } elseif ($lowerKey === 'newsuperior') {
                    $newSuperior = $value;
                }

                continue;
            }

            if ($changetype === 'modify') {
                if (in_array($lowerKey, ['replace', 'add'], true)) {
                    $mode = strtolower($value);
                    $entry[$mode] = $entry[$mode] ?? [];
                    continue;
                }

                if ($lowerKey === 'delete') {
                    $mode = strtolower($value);
                    $entry[$mode] = [];
                    continue;
                }

                if ($mode !== null) {
                    if (! array_key_exists($key, $entry)) {
                        $entry[$key] = [];
                    }

                    $entry[$key][] = $value;
                }

                continue;
            }

            if (! array_key_exists($key, $entry)) {
                $entry[$key] = [];
            }

            $entry[$key][] = $value;
        }

        if ($changetype === 'modify') {
            $entry = $this->compactModifyEntry($entry);
        } else {
            $entry = $this->compactEntry($entry);
        }

        return [
            'dn' => $dn,
            'changetype' => $changetype,
            'entry' => $entry,
            'newrdn' => $newRdn,
            'deleteoldrdn' => $deleteOldRdn,
            'newsuperior' => $newSuperior,
        ];
    }

    private function compactEntry(array $entry): array
    {
        $result = [];

        foreach ($entry as $attribute => $values) {
            if (! is_array($values)) {
                $result[$attribute] = $values;
                continue;
            }

            $values = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $values
            ), fn ($value) => $value !== ''));

            if ($values === []) {
                continue;
            }

            $result[$attribute] = count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }

    private function compactModifyEntry(array $entry): array
    {
        /*
         * Untuk tahap awal, LDIF modify builder kita hanya menghasilkan:
         * replace: attribute
         * attribute: value
         * -
         *
         * ldap_modify() menerima array attribute => value.
         */
        $result = [];

        foreach ($entry as $attribute => $values) {
            if (in_array($attribute, ['replace', 'add', 'delete'], true)) {
                continue;
            }

            if (! is_array($values)) {
                $result[$attribute] = $values;
                continue;
            }

            $values = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $values
            ), fn ($value) => $value !== ''));

            if ($values === []) {
                continue;
            }

            $result[$attribute] = count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }

    private function markRow(string $table, object $row, string $status, string $message): void
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

        if (in_array('applied_at', $columns, true) && $status === 'applied') {
            $updates['applied_at'] = now();
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table($table)->where('id', $row->id)->update($updates);
        }
    }

    private function markBatch(int $batchId, array $summary): void
    {
        $columns = Schema::getColumnListing('import_batches');
        $updates = [];

        if (in_array('status', $columns, true)) {
            $updates['status'] = $summary['failed'] > 0 ? 'failed' : 'success';
        }

        if (in_array('message', $columns, true)) {
            $updates['message'] = 'LDIF import applied. Success: '.$summary['success'].' | Failed: '.$summary['failed'].' | Skipped: '.$summary['skipped'];
        }

        if (in_array('applied_at', $columns, true)) {
            $updates['applied_at'] = now();
        }

        foreach (['apply_stdout', 'stdout', 'execute_stdout', 'output', 'result_output'] as $column) {
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

    private function ldapDnExists(mixed $ldap, string $dn): bool
    {
        $result = @ldap_read($ldap, $dn, '(objectClass=*)', ['dn'], 0, 1);

        if ($result === false) {
            return false;
        }

        $entries = @ldap_get_entries($ldap, $result);

        return is_array($entries) && (int) ($entries['count'] ?? 0) > 0;
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

        $query = DB::table($table)->where($batchColumn, $batchId);

        if (in_array('row_number', $columns, true)) {
            $query->orderBy('row_number');
        } elseif (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        return $query->get()->all();
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

    private function ldapError(mixed $ldap): string
    {
        return '['.@ldap_errno($ldap).'] '.@ldap_error($ldap);
    }

    private function entryDebug(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
