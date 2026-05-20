<?php

namespace App\Services\Ldap;

use App\Models\BulkLdapOperation;
use App\Models\BulkLdapOperationLog;
use Illuminate\Support\Facades\Auth;

class BulkLdapOperationService
{
    public function preview(BulkLdapOperation $operation): array
    {
        $baseDn = trim($operation->base_dn);
        $filter = trim($operation->ldap_filter ?: '(objectClass=*)');

        if ($baseDn === '') {
            return [
                'ok' => false,
                'message' => 'Base DN wajib diisi.',
                'entries' => [],
            ];
        }

        $entries = [
            [
                'dn' => 'uid=example,' . $baseDn,
                'status' => 'preview',
                'planned_action' => $operation->operation_type,
                'reason' => 'Safe mode. Ini dummy preview dulu, belum menyentuh LDAP asli.',
            ],
        ];

        $result = [
            'ok' => true,
            'message' => 'Preview berhasil dibuat dalam safe mode.',
            'entries' => $entries,
            'meta' => [
                'ldap_connection_name' => $operation->ldap_connection_name,
                'base_dn' => $baseDn,
                'filter' => $filter,
                'scope' => $operation->search_scope,
                'size_limit' => $operation->size_limit,
            ],
        ];

        $operation->update([
            'status' => 'previewed',
            'preview_result' => $result,
            'previewed_at' => now(),
        ]);

        return $result;
    }

    public function execute(BulkLdapOperation $operation): array
    {
        $preview = $operation->preview_result;

        if (! is_array($preview) || empty($preview['entries'])) {
            return [
                'ok' => false,
                'message' => 'Preview belum ada. Jalankan Preview dulu.',
                'results' => [],
            ];
        }

        $results = [];

        foreach ($preview['entries'] as $entry) {
            $log = BulkLdapOperationLog::create([
                'bulk_ldap_operation_id' => $operation->id,
                'operation_name' => $operation->operation_name,
                'operation_type' => $operation->operation_type,
                'ldap_connection_name' => $operation->ldap_connection_name,
                'base_dn' => $operation->base_dn,
                'ldap_filter' => $operation->ldap_filter,
                'target_dn' => $entry['dn'] ?? null,
                'status' => 'skipped',
                'reason' => 'Safe mode. LDAP asli belum diubah.',
                'payload' => $operation->toArray(),
                'result' => $entry,
                'executed_by' => Auth::user()?->email ?? Auth::user()?->name ?? 'system',
                'executed_at' => now(),
            ]);

            $results[] = [
                'dn' => $log->target_dn,
                'status' => $log->status,
                'reason' => $log->reason,
            ];
        }

        $result = [
            'ok' => true,
            'message' => 'Execute safe mode selesai. Log tersimpan.',
            'results' => $results,
        ];

        $operation->update([
            'status' => 'executed_safe_mode',
            'execution_result' => $result,
            'executed_at' => now(),
        ]);

        return $result;
    }
}
