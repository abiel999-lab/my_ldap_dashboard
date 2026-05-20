<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapGroupsJob;
use App\Models\Directory\LdapConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapGroupSyncDispatcher
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
                'operation_type' => 'sync_ldap_groups',
                'type' => 'sync_ldap_groups',
                'name' => 'Sync LDAP Groups - '.$connection->name,
                'module' => 'directory.groups',
                'operation_action' => 'sync_ldap_groups',
                'action' => 'sync_ldap_groups',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapConnection::class,
                'target_key' => (string) $connection->id,
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'sync_ldap_groups',
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapGroupsJob::dispatch(
                operationJobId: $operationJob->id,
                ldapConnectionId: $connection->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.groups',
                'action' => 'queue_sync_ldap_groups',
                'status' => 'success',
                'target_type' => LdapConnection::class,
                'target_key' => (string) $connection->id,
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
                'message' => 'LDAP group sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.groups',
                'action' => 'queue_sync_ldap_groups',
                'status' => 'failed',
                'target_type' => LdapConnection::class,
                'target_key' => (string) ($connection?->id ?? 'N/A'),
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
