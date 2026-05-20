<?php

namespace App\Services\Ldap;

use App\Models\BulkLdapOperationLog;
use Illuminate\Support\Facades\Auth;

class BulkLdapOperationService
{
    public function preview(array $data): array
    {
        $baseDn = trim($data['base_dn'] ?? '');
        $filter = trim($data['ldap_filter'] ?? '(objectClass=*)');
        $limit = (int) ($data['size_limit'] ?? 50);

        if ($baseDn === '') {
            return [
                'ok' => false,
                'message' => 'Base DN wajib diisi.',
                'entries' => [],
            ];
        }

        if ($filter === '') {
            $filter = '(objectClass=*)';
        }

        return [
            'ok' => true,
            'message' => 'Preview dummy berhasil. LDAP execution akan kita sambungkan di batch berikutnya.',
            'entries' => [
                [
                    'dn' => 'uid=example,' . $baseDn,
                    'status' => 'preview',
                    'reason' => 'Contoh hasil preview. Belum execute ke LDAP asli.',
                    'planned_action' => $data['operation_type'] ?? 'none',
                ],
            ],
            'meta' => [
                'base_dn' => $baseDn,
                'filter' => $filter,
                'limit' => $limit,
            ],
        ];
    }

    public function execute(array $data, array $previewEntries = []): array
    {
        $results = [];

        foreach ($previewEntries as $entry) {
            $log = BulkLdapOperationLog::create([
                'operation_name' => $data['operation_name'] ?? null,
                'operation_type' => $data['operation_type'] ?? 'unknown',
                'ldap_connection_name' => $data['ldap_connection_name'] ?? null,
                'base_dn' => $data['base_dn'] ?? null,
                'ldap_filter' => $data['ldap_filter'] ?? null,
                'target_dn' => $entry['dn'] ?? null,
                'status' => 'skipped',
                'reason' => 'Batch 1 masih mode aman. Belum melakukan perubahan LDAP asli.',
                'payload' => $data,
                'result' => $entry,
                'executed_by' => Auth::user()?->email ?? Auth::user()?->name ?? 'system',
                'executed_at' => now(),
            ]);

            $results[] = [
                'dn' => $entry['dn'] ?? null,
                'status' => $log->status,
                'reason' => $log->reason,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Execution dummy selesai dan log tersimpan.',
            'results' => $results,
        ];
    }
}
