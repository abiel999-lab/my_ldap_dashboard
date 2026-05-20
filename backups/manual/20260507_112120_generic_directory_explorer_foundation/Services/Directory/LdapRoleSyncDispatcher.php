<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapRolesJob;
use App\Models\Directory\LdapRoleEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapRoleSyncDispatcher
{
    public function queue(): array
    {
        try {
            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'sync_ldap_roles_from_group_cache',
                'type' => 'sync_ldap_roles_from_group_cache',
                'name' => 'Sync LDAP Roles From Group Cache',
                'module' => 'directory.roles',
                'operation_action' => 'sync_ldap_roles_from_group_cache',
                'action' => 'sync_ldap_roles_from_group_cache',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapRoleEntry::class,
                'target_key' => 'ldap_group_cache',
                'target_dn' => null,
                'ldap_connection_id' => null,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'sync_ldap_roles_from_group_cache',
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapRolesJob::dispatch(
                operationJobId: $operationJob->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.roles',
                'action' => 'queue_sync_ldap_roles_from_group_cache',
                'status' => 'success',
                'target_type' => LdapRoleEntry::class,
                'target_key' => 'ldap_group_cache',
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'queue' => 'ldap',
                    'source' => 'ldap_group_entries',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP role sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.roles',
                'action' => 'queue_sync_ldap_roles_from_group_cache',
                'status' => 'failed',
                'target_type' => LdapRoleEntry::class,
                'target_key' => 'ldap_group_cache',
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'operation_job' => null,
            ];
        }
    }
}
