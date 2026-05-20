<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapDirectoryExplorerJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapDirectoryExplorerSyncDispatcher
{
    public function queue(?LdapConnection $connection = null): array
    {
        try {
            $connection = $connection ?: LdapConnection::query()->where('is_default', true)->first();

            if (! $connection) {
                return [
                    'ok' => false,
                    'message' => 'No default LDAP connection found.',
                    'operation_job' => null,
                ];
            }

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'sync_ldap_directory_explorer',
                'type' => 'sync_ldap_directory_explorer',
                'name' => 'Sync Directory Explorer - '.$connection->name,
                'module' => 'directory.explorer',
                'operation_action' => 'sync_ldap_directory_explorer',
                'action' => 'sync_ldap_directory_explorer',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapDirectoryEntry::class,
                'target_key' => 'ldap_directory_explorer',
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'sync_ldap_directory_explorer',
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapDirectoryExplorerJob::dispatch(
                operationJobId: $operationJob->id,
                ldapConnectionId: $connection->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.explorer',
                'action' => 'queue_sync_ldap_directory_explorer',
                'status' => 'success',
                'target_type' => LdapDirectoryEntry::class,
                'target_key' => 'ldap_directory_explorer',
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'queue' => 'ldap',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP directory explorer sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.explorer',
                'action' => 'queue_sync_ldap_directory_explorer',
                'status' => 'failed',
                'target_type' => LdapDirectoryEntry::class,
                'target_key' => 'ldap_directory_explorer',
                'target_dn' => $connection?->base_dn,
                'ldap_connection_id' => $connection?->id,
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
