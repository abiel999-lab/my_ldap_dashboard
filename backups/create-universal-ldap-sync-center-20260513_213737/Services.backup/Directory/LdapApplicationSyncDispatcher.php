<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapApplicationsJob;
use App\Models\Directory\LdapApplicationEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapApplicationSyncDispatcher
{
    public function queue(): array
    {
        try {
            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'sync_ldap_applications_from_group_cache',
                'type' => 'sync_ldap_applications_from_group_cache',
                'name' => 'Sync LDAP Applications From Group Cache',
                'module' => 'directory.applications',
                'operation_action' => 'sync_ldap_applications_from_group_cache',
                'action' => 'sync_ldap_applications_from_group_cache',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapApplicationEntry::class,
                'target_key' => 'ldap_group_cache',
                'target_dn' => null,
                'ldap_connection_id' => null,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'sync_ldap_applications_from_group_cache',
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapApplicationsJob::dispatch(
                operationJobId: $operationJob->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.applications',
                'action' => 'queue_sync_ldap_applications_from_group_cache',
                'status' => 'success',
                'target_type' => LdapApplicationEntry::class,
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
                'message' => 'LDAP application sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.applications',
                'action' => 'queue_sync_ldap_applications_from_group_cache',
                'status' => 'failed',
                'target_type' => LdapApplicationEntry::class,
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
