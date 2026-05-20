<?php

namespace App\Services\Operations;

use App\Jobs\Operations\ExecuteLdifExportJob;
use App\Models\Operations\LdifExportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

class LdifExportDispatcher
{
    public function queueExport(LdifExportBatch $batch): OperationJob
    {
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
            'target_dn' => $batch->base_dn,
            'ldap_connection_id' => $batch->ldap_connection_id,
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'metadata' => [
                'source' => 'filament',
                'action' => 'execute_ldif_export',
                'target_type' => LdifExportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldif_export_batch_id' => $batch->id,
                'base_dn' => $batch->base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
                'queue' => 'export',
                'actor_user_id' => Auth::id(),
            ],
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
            'target_dn' => $batch->base_dn,
            'ldap_connection_id' => $batch->ldap_connection_id,
            'operation_job_id' => $operationJob->id,
            'request_payload' => [
                'name' => $batch->name,
                'base_dn' => $batch->base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
                'queue' => 'export',
            ],
        ]);

        return $operationJob;
    }
}
