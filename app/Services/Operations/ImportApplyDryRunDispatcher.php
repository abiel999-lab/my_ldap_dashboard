<?php

namespace App\Services\Operations;

use App\Jobs\Operations\VerifyImportApplyLdapDryRunJob;
use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportApplyDryRunDispatcher
{
    public function queueVerify(ImportApplyPlan $plan): array
    {
        try {
            $plan->refresh();

            if (! $plan->canVerifyDryRun()) {
                return [
                    'ok' => false,
                    'message' => 'Plan cannot be verified. It must be approved, successful, safe, dry-run, non-destructive, and have an LDIF file.',
                    'operation_job' => null,
                ];
            }

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'import_apply_ldapadd_dry_run',
                'type' => 'import_apply_ldapadd_dry_run',
                'name' => 'Verify LDAP Apply Dry Run - '.$plan->name,
                'module' => 'operations.import',
                'operation_action' => 'verify_import_apply_ldapadd_dry_run',
                'action' => 'verify_import_apply_ldapadd_dry_run',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'verify_import_apply_ldapadd_dry_run',
                    'target_type' => ImportApplyPlan::class,
                    'target_key' => (string) $plan->id,
                    'target_dn' => $plan->importBatch?->base_dn,
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'queue' => 'import',
                    'actor_user_id' => Auth::id(),
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                    'ldap_will_change' => false,
                ],
            ]);

            VerifyImportApplyLdapDryRunJob::dispatch(
                operationJobId: $operationJob->id,
                importApplyPlanId: $plan->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_apply_ldapadd_dry_run',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                    'ldap_will_change' => false,
                    'queue' => 'import',
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'ldapadd dry-run verification queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_import_apply_ldapadd_dry_run',
                'status' => 'failed',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
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
