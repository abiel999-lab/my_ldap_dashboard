<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LdapImportPlanRepairService
{
    public function repair(int $batchId): array
    {
        $batch = DB::table('import_batches')->where('id', $batchId)->first();

        if (! $batch) {
            throw new \RuntimeException('Import batch not found: '.$batchId);
        }

        $rowTable = $this->detectRowTable($batchId);

        if (! $rowTable) {
            throw new \RuntimeException('Import rows table not found for batch '.$batchId);
        }

        $rows = $this->rowsForBatch($rowTable, $batchId);
        $columns = Schema::getColumnListing($rowTable);

        $summary = [
            'total' => count($rows),
            'valid' => 0,
            'invalid' => 0,
            'create' => 0,
            'update' => 0,
            'delete' => 0,
            'skip' => 0,
            'fail' => 0,
            'repaired' => 0,
        ];

        foreach ($rows as $row) {
            $payload = $this->payloadFromRow($row);
            $action = strtolower(trim((string) ($payload['action'] ?? $payload['changetype'] ?? $payload['operation'] ?? 'create')));
            $dn = trim((string) ($payload['dn'] ?? $payload['DN'] ?? $row->target_dn ?? ''));

            $updates = [];

            if ($action === 'modify') {
                $action = 'update';
            }

            if ($action === 'add') {
                $action = 'create';
            }

            if ($action === 'delete') {
                $this->applyCommonRowUpdates(
                    $updates,
                    $columns,
                    'valid',
                    'delete',
                    $dn,
                    $this->identifierFromDn($dn),
                    'DELETE preview generated.',
                    null,
                    $payload
                );

                if ($dn === '') {
                    $this->applyCommonRowUpdates(
                        $updates,
                        $columns,
                        'invalid',
                        'fail',
                        $dn,
                        null,
                        'Delete requires dn.',
                        'Delete requires dn.',
                        $payload
                    );
                }

                $summary['delete'] += ($dn !== '') ? 1 : 0;
                $summary['valid'] += ($dn !== '') ? 1 : 0;
                $summary['invalid'] += ($dn === '') ? 1 : 0;
                $summary['fail'] += ($dn === '') ? 1 : 0;
                $summary['repaired']++;

                $this->updateRow($rowTable, $row, $updates);
                continue;
            }

            if (in_array($action, ['update', 'replace', 'upsert'], true)) {
                $editablePayload = $payload;

                foreach (['action', 'operation', 'changetype', 'dn', 'DN', 'identifier'] as $blocked) {
                    unset($editablePayload[$blocked]);
                }

                $hasChange = count(array_filter($editablePayload, fn ($value) => $value !== null && $value !== '')) > 0;

                $status = ($dn !== '' && $hasChange) ? 'valid' : 'invalid';
                $plan = ($dn !== '' && $hasChange) ? 'update' : 'fail';
                $message = ($dn !== '' && $hasChange)
                    ? 'UPDATE preview generated.'
                    : 'Update requires dn and at least one attribute to modify.';

                $this->applyCommonRowUpdates(
                    $updates,
                    $columns,
                    $status,
                    $plan,
                    $dn,
                    $this->identifierFromPayloadOrDn($payload, $dn),
                    $message,
                    $status === 'invalid' ? $message : null,
                    $payload
                );

                $summary['update'] += ($plan === 'update') ? 1 : 0;
                $summary['valid'] += ($status === 'valid') ? 1 : 0;
                $summary['invalid'] += ($status === 'invalid') ? 1 : 0;
                $summary['fail'] += ($plan === 'fail') ? 1 : 0;
                $summary['repaired']++;

                $this->updateRow($rowTable, $row, $updates);
                continue;
            }

            if ($action === 'create') {
                $summary['create']++;
                $summary['valid'] += str_contains((string) ($row->status ?? ''), 'valid') || str_contains((string) ($row->status ?? ''), 'preview') ? 1 : 0;
                continue;
            }
        }

        $this->updateBatchSummary($batchId, $summary);

        return [
            'batch_id' => $batchId,
            'row_table' => $rowTable,
            ...$summary,
        ];
    }

    private function applyCommonRowUpdates(
        array &$updates,
        array $columns,
        string $status,
        string $plan,
        string $dn,
        ?string $identifier,
        string $message,
        ?string $conflict,
        array $payload
    ): void {
        if (in_array('status', $columns, true)) {
            $updates['status'] = $status;
        }

        foreach (['action_plan', 'plan'] as $column) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = $plan;
                break;
            }
        }

        if (in_array('target_dn', $columns, true)) {
            $updates['target_dn'] = $dn;
        }

        if (in_array('target_identifier', $columns, true)) {
            $updates['target_identifier'] = $identifier;
        }

        if (in_array('message', $columns, true)) {
            $updates['message'] = $message;
        }

        if (in_array('conflict_reason', $columns, true)) {
            $updates['conflict_reason'] = $conflict;
        }

        if (in_array('validation_errors', $columns, true)) {
            $updates['validation_errors'] = $conflict ? json_encode([$conflict], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }

        if (in_array('mapped_payload', $columns, true)) {
            $updates['mapped_payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }
    }

    private function updateRow(string $table, object $row, array $updates): void
    {
        if ($updates === [] || ! property_exists($row, 'id')) {
            return;
        }

        DB::table($table)->where('id', $row->id)->update($updates);
    }

    private function updateBatchSummary(int $batchId, array $summary): void
    {
        $columns = Schema::getColumnListing('import_batches');
        $updates = [];

        $map = [
            'total_rows' => 'total',
            'rows_count' => 'total',
            'row_count' => 'total',
            'valid_rows' => 'valid',
            'invalid_rows' => 'invalid',
            'will_create' => 'create',
            'create_count' => 'create',
            'will_update' => 'update',
            'update_count' => 'update',
            'will_delete' => 'delete',
            'delete_count' => 'delete',
            'will_skip' => 'skip',
            'skip_count' => 'skip',
            'will_fail' => 'fail',
            'failed_rows' => 'fail',
            'fail_count' => 'fail',
        ];

        foreach ($map as $column => $key) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = $summary[$key] ?? 0;
            }
        }

        if (in_array('status', $columns, true)) {
            $updates['status'] = $summary['invalid'] > 0 ? 'preview_completed_with_issues' : 'preview_completed';
        }

        if (in_array('message', $columns, true)) {
            $updates['message'] = 'Import preview plans repaired. Update/delete actions normalized.';
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

    private function identifierFromPayloadOrDn(array $payload, string $dn): ?string
    {
        foreach (['identifier', 'uid', 'cn', 'ou'] as $key) {
            if (! empty($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return $this->identifierFromDn($dn);
    }

    private function identifierFromDn(string $dn): ?string
    {
        if ($dn === '' || ! str_contains($dn, '=')) {
            return null;
        }

        $first = explode(',', $dn, 2)[0] ?? '';
        $parts = explode('=', $first, 2);

        return $parts[1] ?? null;
    }
}
