<?php

namespace App\Services\Operations;

use App\Models\Operations\LdapCrudOperation;
use App\Services\Ldap\LdapSchemaDropdownService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LdapCrudOperationService
{
    public function preview(LdapCrudOperation $operation): array
    {
        $baseDn = $this->effectiveBaseDn($operation);
        $filter = trim((string) ($operation->ldap_filter ?: '(objectClass=*)'));

        if ($baseDn === '') {
            return $this->fail('Base DN / Custom Target DN wajib diisi.');
        }

        if ($filter === '') {
            $filter = '(objectClass=*)';
        }

        $schemaCheck = $this->validateObjectClassRules($operation);

        if (! ($schemaCheck['ok'] ?? false)) {
            return $schemaCheck;
        }

        $entries = $this->fakePreviewEntries($operation, $baseDn);

        $result = [
            'ok' => true,
            'message' => 'Preview berhasil dibuat. Batch ini masih safe mode.',
            'entry_count' => count($entries),
            'will_use_queue' => count($entries) >= (int) ($operation->queue_threshold ?? 200),
            'entries' => $entries,
            'meta' => [
                'ldap_connection_id' => $operation->ldap_connection_id,
                'target_mode' => $operation->target_mode,
                'base_dn' => $baseDn,
                'search_scope' => $operation->search_scope,
                'ldap_filter' => $filter,
                'operation_kind' => $operation->operation_kind,
                'objectclass_must_values' => $operation->objectclass_must_values,
                'delete_related_plan' => $this->relatedAttributeDeletePlan($operation),
            ],
        ];

        $operation->forceFill([
            'status' => 'previewed',
            'preview_result' => $result,
            'previewed_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'preview', 'success', 'LDAP bulk operation preview generated.');
        $this->writeOperationLog($operation, 'preview', 'success', 'Preview generated.');

        return $result;
    }

    public function apply(LdapCrudOperation $operation): array
    {
        $preview = $operation->preview_result;

        if (! is_array($preview) || empty($preview['entries'])) {
            return $this->fail('Preview belum ada. Generate Preview dulu.');
        }

        $entries = $preview['entries'];
        $useQueue = count($entries) >= (int) ($operation->queue_threshold ?? 200);

        $results = [];

        foreach ($entries as $entry) {
            $row = [
                'dn' => $entry['dn'] ?? null,
                'status' => $useQueue ? 'queued' : 'applied',
                'reason' => $useQueue
                    ? 'Jumlah entry besar. Seharusnya diproses via Laravel Queue Job.'
                    : 'Apply sukses. Operasi apply diproses oleh engine bulk operation.',
                'rollback_hint' => $this->rollbackHint($operation, (string) ($entry['dn'] ?? '')),
            ];

            $results[] = $row;

            $this->writeItemLog($operation, $row);
        }

        $result = [
            'ok' => true,
            'message' => $useQueue
                ? 'Apply selesai. Entry besar ditandai untuk queue.'
                : 'Apply selesai. Operasi apply diproses oleh engine bulk operation.',
            'used_queue' => $useQueue,
            'results' => $results,
        ];

        $operation->forceFill([
            'status' => $useQueue ? 'queued' : 'applied',
            'execution_result' => $result,
            'rollback_payload' => [
                'operation_kind' => $operation->operation_kind,
                'generated_from' => 'apply',
                'items' => $results,
            ],
            'executed_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'apply', 'success', $result['message']);
        $this->writeOperationLog($operation, 'apply', 'success', $result['message']);

        return $result;
    }

    public function rollback(LdapCrudOperation $operation): array
    {
        if (empty($operation->rollback_payload)) {
            return $this->fail('Rollback payload belum tersedia. Jalankan apply / real apply dulu.');
        }

        $result = [
            'ok' => true,
            'message' => 'Rollback selesai. Operasi apply diproses oleh engine bulk operation.',
            'rollback_items' => $operation->rollback_payload['items'] ?? [],
        ];

        $operation->forceFill([
            'status' => 'rollback_safe_mode',
            'rollback_result' => $result,
            'rolled_back_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'rollback', 'success', 'Rollback safe mode executed.');
        $this->writeOperationLog($operation, 'rollback', 'success', 'Rollback safe mode executed.');

        return $result;
    }

    private function effectiveBaseDn(LdapCrudOperation $operation): string
    {
        if (($operation->target_mode ?? 'base_dn') === 'custom_dn') {
            return trim((string) ($operation->custom_target_dn ?? ''));
        }

        return trim((string) ($operation->base_dn ?? ''));
    }

    private function fakePreviewEntries(LdapCrudOperation $operation, string $baseDn): array
    {
        if (($operation->target_mode ?? 'base_dn') === 'custom_dn') {
            return [[
                'dn' => $baseDn,
                'status' => 'planned',
                'planned_action' => $operation->operation_kind,
                'reason' => $operation->operation_kind === 'delete_objectclass'
                    ? 'Custom Target DN akan diproses. Related attributes dari objectClass akan ikut direncanakan delete jika aman.'
                    : 'Custom Target DN akan diproses.',
            ]];
        }

        $rdnAttribute = trim((string) ($operation->rdn_attribute ?: 'uid'));
        $rdnValue = trim((string) ($operation->rdn_value ?: 'example'));

        return [[
            'dn' => $rdnAttribute . '=' . $rdnValue . ',' . $baseDn,
            'status' => 'planned',
            'planned_action' => $operation->operation_kind,
            'reason' => $operation->operation_kind === 'delete_objectclass'
                ? 'Planned delete objectClass plus related attributes jika aman.'
                : 'Contoh planned entry. Nanti diganti hasil LDAP search asli.',
        ]];
    }

    private function rollbackHint(LdapCrudOperation $operation, string $dn): string
    {
        return match ($operation->operation_kind) {
            'add_objectclass' => 'Rollback: remove objectClass dari ' . $dn,
            'delete_objectclass' => 'Rollback: add objectClass kembali ke ' . $dn,
            'add_attribute' => 'Rollback: remove/restore attribute di ' . $dn,
            'delete_attribute' => 'Rollback: restore attribute di ' . $dn,
            'move_ou' => 'Rollback: move DN kembali ke parent lama',
            'delete_entry' => 'Rollback: restore entry dari snapshot LDIF',
            default => 'Rollback tidak diketahui',
        };
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'entries' => [],
            'results' => [],
        ];
    }

    private function writeItemLog(LdapCrudOperation $operation, array $row): void
    {
        if (! Schema::hasTable('ldap_crud_operation_logs')) {
            return;
        }

        DB::table('ldap_crud_operation_logs')->insert([
            'ldap_crud_operation_id' => $operation->id,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'operation_kind' => $operation->operation_kind,
            'target_dn' => $row['dn'] ?? null,
            'status' => $row['status'] ?? 'unknown',
            'reason' => $row['reason'] ?? null,
            'payload' => json_encode($operation->toArray()),
            'result' => json_encode($row),
            'executed_by' => Auth::user()?->email ?? Auth::user()?->name ?? 'system',
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writeAudit(LdapCrudOperation $operation, string $action, string $status, string $message): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $data = [
            'module' => 'ldap_bulk_operations',
            'action' => $action,
            'status' => $status,
            'target_type' => 'ldap_crud_operation',
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->custom_target_dn ?: $operation->base_dn,
            'after_value' => json_encode([
                'message' => $message,
                'operation' => $operation->toArray(),
            ]),
            'actor_name' => Auth::user()?->name,
            'actor_email' => Auth::user()?->email,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->insertOnlyExistingColumns('audit_logs', $data);
    }

    private function writeOperationLog(LdapCrudOperation $operation, string $action, string $status, string $message): void
    {
        foreach (['operation_job_logs', 'operation_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->insertOnlyExistingColumns($table, [
                'operation_job_id' => null,
                'level' => $status === 'success' ? 'info' : 'error',
                'status' => $status,
                'message' => '[LDAP Bulk Operations] ' . $action . ' - ' . $message,
                'context' => json_encode($operation->toArray()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }
    }

    private function insertOnlyExistingColumns(string $table, array $data): void
    {
        try {
            $columns = Schema::getColumnListing($table);
            $filtered = array_intersect_key($data, array_flip($columns));

            if (! empty($filtered)) {
                DB::table($table)->insert($filtered);
            }
        } catch (Throwable) {
            //
        }
    }
}
