<?php

namespace App\Services\Directory;

use App\Jobs\Directory\SyncLdapSchemaJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapSchemaEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapSchemaSyncDispatcher
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
                'operation_type' => 'sync_ldap_schema',
                'type' => 'sync_ldap_schema',
                'name' => 'Sync LDAP Schema - '.$connection->name,
                'module' => 'directory.schema',
                'operation_action' => 'sync_ldap_schema',
                'action' => 'sync_ldap_schema',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapSchemaEntry::class,
                'target_key' => 'cn=subschema',
                'target_dn' => 'cn=subschema',
                'ldap_connection_id' => $connection->id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'sync_ldap_schema',
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            SyncLdapSchemaJob::dispatch(
                operationJobId: $operationJob->id,
                ldapConnectionId: $connection->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.schema',
                'action' => 'queue_sync_ldap_schema',
                'status' => 'success',
                'target_type' => LdapSchemaEntry::class,
                'target_key' => 'cn=subschema',
                'target_dn' => 'cn=subschema',
                'ldap_connection_id' => $connection->id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'queue' => 'ldap',
                    'schema_base_dn' => 'cn=subschema',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP schema sync queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.schema',
                'action' => 'queue_sync_ldap_schema',
                'status' => 'failed',
                'target_type' => LdapSchemaEntry::class,
                'target_key' => 'cn=subschema',
                'target_dn' => 'cn=subschema',
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
