<?php

namespace App\Services\Operations;

use App\Jobs\Operations\ExecuteLdifExportJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdifExportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdifExportDispatcher
{
    public function queueExport(LdifExportBatch $batch): array
    {
        try {
            $batch->refresh();

            $connection = $batch->ldapConnection;

            if (! $connection && filled($batch->ldap_connection_id)) {
                $connection = LdapConnection::query()->find($batch->ldap_connection_id);
            }

            if (! $connection) {
                $connection = LdapConnection::query()
                    ->where('is_default', true)
                    ->first();
            }

            if (! $connection) {
                return [
                    'ok' => false,
                    'message' => 'No LDAP connection found. Please create or set a default LDAP connection first.',
                    'operation_job' => null,
                ];
            }

            if (! $connection->is_active) {
                return [
                    'ok' => false,
                    'message' => 'Selected LDAP connection is not active.',
                    'operation_job' => null,
                ];
            }

            if (blank($connection->bind_dn) || blank($connection->bind_password)) {
                return [
                    'ok' => false,
                    'message' => 'Selected/default LDAP connection does not have bind DN/password configured.',
                    'operation_job' => null,
                ];
            }

            if (blank($batch->effective_base_dn)) {
                return [
                    'ok' => false,
                    'message' => 'Effective export DN is required before queueing LDIF export.',
                    'operation_job' => null,
                ];
            }

            if (blank($batch->filter)) {
                return [
                    'ok' => false,
                    'message' => 'LDAP filter is required before queueing LDIF export.',
                    'operation_job' => null,
                ];
            }

            $filter = trim((string) $batch->filter);

            if (! str_starts_with($filter, '(') || ! str_ends_with($filter, ')')) {
                return [
                    'ok' => false,
                    'message' => 'LDAP filter must start with ( and end with ). Example: (objectClass=*)',
                    'operation_job' => null,
                ];
            }

            $batch->forceFill([
                'ldap_connection_id' => $connection->id,
                'safe_mode' => true,
                'preview_mode' => false,
                'destructive' => false,
            ])->save();

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'ldif_export',
                'type' => 'ldif_export',
                'name' => 'LDIF Export - '.$batch->name,
                'module' => 'operations.export',
                'operation_action' => 'execute_ldif_export',
                'action' => 'execute_ldif_export',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdifExportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->effective_base_dn,
                'ldap_connection_id' => $connection->id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'execute_ldif_export',
                    'target_type' => LdifExportBatch::class,
                    'target_key' => (string) $batch->id,
                    'target_dn' => $batch->effective_base_dn,
                    'ldif_export_batch_id' => $batch->id,
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name,
                    'export_scope' => $batch->export_scope,
                    'search_scope' => $batch->search_scope,
                    'base_dn' => $batch->base_dn,
                    'effective_base_dn' => $batch->effective_base_dn,
                    'filter' => $batch->filter,
                    'attributes' => $batch->attribute_list,
                    'size_limit' => $batch->size_limit,
                    'queue' => 'export',
                    'actor_user_id' => Auth::id(),
                ],
            ]);

            app(OperationJobTracker::class)->log($operationJob, 'info', 'LDIF export queued.', [
                'ldif_export_batch_id' => $batch->id,
                'ldap_connection_id' => $connection->id,
                'effective_base_dn' => $batch->effective_base_dn,
                'filter' => $batch->filter,
            ]);

            $batch->forceFill([
                'status' => 'queued',
                'operation_job_id' => $operationJob->id,
                'message' => 'LDIF export queued.',
            ])->save();

            ExecuteLdifExportJob::dispatch(
                operationJobId: $operationJob->id,
                ldifExportBatchId: $batch->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.export',
                'action' => 'queue_ldif_export',
                'status' => 'success',
                'target_type' => LdifExportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->effective_base_dn,
                'ldap_connection_id' => $connection->id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'export_scope' => $batch->export_scope,
                    'search_scope' => $batch->search_scope,
                    'base_dn' => $batch->base_dn,
                    'effective_base_dn' => $batch->effective_base_dn,
                    'filter' => $batch->filter,
                    'attributes' => $batch->attribute_list,
                    'size_limit' => $batch->size_limit,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'LDIF export queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.export',
                'action' => 'queue_ldif_export',
                'status' => 'failed',
                'target_type' => LdifExportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->effective_base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
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
