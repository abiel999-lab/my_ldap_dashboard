<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapUsersJob;
use App\Models\Directory\LdapConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapUserSyncDispatcher
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
                'operation_type' => 'sync_ldap_users',
                'type' => 'sync_ldap_users',
                'name' => 'Sync LDAP Users - '.$connection->name,
                'module' => 'directory.users',
                'operation_action' => 'sync_ldap_users',
                'action' => 'sync_ldap_users',
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
                    'action' => 'sync_ldap_users',
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapUsersJob::dispatch(
                operationJobId: $operationJob->id,
                ldapConnectionId: $connection->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'queue_sync_ldap_users',
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
                'message' => 'LDAP user sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'queue_sync_ldap_users',
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
