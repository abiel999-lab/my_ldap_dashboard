<?php

namespace App\Services\Operations;

use App\Jobs\Operations\PreviewImportBatchJob;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportPreviewDispatcher
{
    public function queuePreview(ImportBatch $batch): array
    {
        try {
            $batch->refresh();

            if (! $batch->hasUploadFile()) {
                return [
                    'ok' => false,
                    'message' => 'Import file is missing. Upload a file before preview.',
                    'operation_job' => null,
                ];
            }

            if (! in_array($batch->import_type, ['csv', 'json', 'ldif'], true)) {
                return [
                    'ok' => false,
                    'message' => 'Unsupported import type. Current foundation supports csv, json, and ldif.',
                    'operation_job' => null,
                ];
            }

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'import_preview',
                'type' => 'import_preview',
                'name' => 'Import Preview - '.$batch->name,
                'module' => 'operations.import',
                'operation_action' => 'preview_import',
                'action' => 'preview_import',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'preview_import',
                    'target_type' => ImportBatch::class,
                    'target_key' => (string) $batch->id,
                    'target_dn' => $batch->base_dn,
                    'import_batch_id' => $batch->id,
                    'import_type' => $batch->import_type,
                    'file_path' => $batch->file_path,
                    'queue' => 'import',
                    'actor_user_id' => Auth::id(),
                ],
            ]);

            $batch->forceFill([
                'status' => 'preview_queued',
                'operation_job_id' => $operationJob->id,
                'message' => 'Import preview queued.',
            ])->save();

            PreviewImportBatchJob::dispatch(
                operationJobId: $operationJob->id,
                importBatchId: $batch->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_preview',
                'status' => 'success',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'name' => $batch->name,
                    'import_type' => $batch->import_type,
                    'file_path' => $batch->file_path,
                    'base_dn' => $batch->base_dn,
                    'identifier_attribute' => $batch->identifier_attribute,
                    'queue' => 'import',
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Import preview queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_preview',
                'status' => 'failed',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
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
