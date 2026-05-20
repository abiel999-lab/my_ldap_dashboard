<?php

namespace App\Services\Operations;

use App\Jobs\Operations\GenerateImportApplyLdifPlanJob;
use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportApplyPlanDispatcher
{
    public function queueGenerateLdifDryRun(ImportBatch $batch): array
    {
        try {
            $batch->refresh();

            if (! in_array($batch->status, ['previewed', 'previewed_with_errors', 'ready_to_apply'], true)) {
                return [
                    'ok' => false,
                    'message' => 'Import batch must be previewed before generating apply LDIF.',
                    'operation_job' => null,
                    'plan' => null,
                ];
            }

            if ($batch->valid_rows <= 0) {
                return [
                    'ok' => false,
                    'message' => 'No valid rows available to generate apply LDIF.',
                    'operation_job' => null,
                    'plan' => null,
                ];
            }

            $plan = ImportApplyPlan::query()->create([
                'import_batch_id' => $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'name' => 'Apply LDIF Dry Run - '.$batch->name,
                'status' => 'queued',
                'plan_type' => 'ldif_dry_run',
                'total_rows' => $batch->total_rows,
                'planned_create_rows' => 0,
                'planned_update_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'message' => 'Apply LDIF dry run queued.',
            ]);

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'import_apply_ldif_dry_run',
                'type' => 'import_apply_ldif_dry_run',
                'name' => 'Generate Import Apply LDIF - '.$batch->name,
                'module' => 'operations.import',
                'operation_action' => 'generate_import_apply_ldif_dry_run',
                'action' => 'generate_import_apply_ldif_dry_run',
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
                    'action' => 'generate_import_apply_ldif_dry_run',
                    'target_type' => ImportBatch::class,
                    'target_key' => (string) $batch->id,
                    'target_dn' => $batch->base_dn,
                    'import_batch_id' => $batch->id,
                    'import_apply_plan_id' => $plan->id,
                    'queue' => 'import',
                    'actor_user_id' => Auth::id(),
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                ],
            ]);

            $plan->forceFill([
                'operation_job_id' => $operationJob->id,
            ])->save();

            GenerateImportApplyLdifPlanJob::dispatch(
                operationJobId: $operationJob->id,
                importBatchId: $batch->id,
                importApplyPlanId: $plan->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_apply_ldif_dry_run',
                'status' => 'success',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'import_batch_id' => $batch->id,
                    'import_apply_plan_id' => $plan->id,
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                    'queue' => 'import',
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Apply LDIF dry run queued.',
                'operation_job' => $operationJob,
                'plan' => $plan,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_apply_ldif_dry_run',
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
                'plan' => null,
            ];
        }
    }
}
