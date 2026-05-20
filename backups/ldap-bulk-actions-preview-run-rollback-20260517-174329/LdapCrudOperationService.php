<?php

namespace App\Services\Operations;

use App\Models\Operations\LdapCrudOperation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LdapCrudOperationService
{
    public function preview(LdapCrudOperation $operation): array
    {
        $baseDn = trim((string) ($operation->base_dn ?? ''));
        $filter = trim((string) ($operation->ldap_filter ?? '(objectClass=*)'));

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

        $entries = [
            [
                'dn' => 'uid=example,' . $baseDn,
                'status' => 'preview',
                'planned_action' => $operation->operation_kind,
                'reason' => 'Safe preview. LDAP asli belum disentuh.',
            ],
        ];

        $result = [
            'ok' => true,
            'message' => 'Preview berhasil dibuat. Ini masih safe mode.',
            'entries' => $entries,
            'rules' => [
                'add_objectclass' => 'Skip jika target DN sudah punya objectClass. Jika objectClass punya MUST attribute, nilai wajib harus disiapkan dulu.',
                'delete_objectclass' => 'Skip jika target DN tidak punya objectClass tersebut.',
                'add_attribute' => 'Skip jika attribute tidak diizinkan oleh objectClass target.',
                'delete_attribute' => 'Skip jika attribute tidak ada atau attribute adalah MUST dari objectClass aktif.',
                'move_ou' => 'Move hanya ganti parent OU, tidak rename RDN.',
                'delete_entry' => 'Delete entry wajib preview dulu sebelum execute.',
            ],
            'meta' => [
                'ldap_connection_id' => $operation->ldap_connection_id,
                'base_dn' => $baseDn,
                'search_scope' => $operation->search_scope,
                'ldap_filter' => $filter,
                'size_limit' => $operation->size_limit,
                'operation_kind' => $operation->operation_kind,
            ],
        ];

        $operation->forceFill([
            'status' => 'previewed',
            'preview_result' => $result,
            'previewed_at' => now(),
        ])->save();

        return $result;
    }

    public function execute(LdapCrudOperation $operation): array
    {
        $preview = $operation->preview_result;

        if (! is_array($preview) || empty($preview['entries'])) {
            return [
                'ok' => false,
                'message' => 'Preview belum ada. Jalankan Preview dulu sebelum Execute.',
                'results' => [],
            ];
        }

        $results = [];

        foreach ($preview['entries'] as $entry) {
            $row = [
                'dn' => $entry['dn'] ?? null,
                'status' => 'skipped',
                'reason' => 'Safe mode. Belum melakukan perubahan LDAP asli.',
            ];

            $results[] = $row;

            if (DB::getSchemaBuilder()->hasTable('ldap_crud_operation_logs')) {
                DB::table('ldap_crud_operation_logs')->insert([
                    'ldap_crud_operation_id' => $operation->id,
                    'ldap_connection_id' => $operation->ldap_connection_id,
                    'operation_kind' => $operation->operation_kind,
                    'target_dn' => $row['dn'],
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'payload' => json_encode($operation->toArray()),
                    'result' => json_encode($row),
                    'executed_by' => Auth::user()?->email ?? Auth::user()?->name ?? 'system',
                    'executed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $result = [
            'ok' => true,
            'message' => 'Execute safe mode selesai. Log tersimpan.',
            'results' => $results,
        ];

        $operation->forceFill([
            'status' => 'executed_safe_mode',
            'execution_result' => $result,
            'executed_at' => now(),
        ])->save();

        return $result;
    }
}
