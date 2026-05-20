<?php

namespace App\Services\Operations;

use App\Jobs\Operations\PostApplyVerifyImportLdapJob;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportPostApplyVerificationDispatcher
{
    public function queueVerify(ImportApplyPlan $plan): array
    {
        try {
            $plan->refresh();

            if (! $plan->canVerifyPostApply()) {
                return [
                    'ok' => false,
                    'message' => 'Plan cannot be post-apply verified. It must be applied first.',
                    'operation_job' => null,
                ];
            }

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'post_apply_verify_ldap_entries',
                'type' => 'post_apply_verify_ldap_entries',
                'name' => 'Post-Apply Verify LDAP Entries - '.$plan->name,
                'module' => 'operations.import',
                'operation_action' => 'post_apply_verify_ldap_entries',
                'action' => 'post_apply_verify_ldap_entries',
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
                    'action' => 'post_apply_verify_ldap_entries',
                    'target_type' => ImportApplyPlan::class,
                    'target_key' => (string) $plan->id,
                    'target_dn' => $plan->importBatch?->base_dn,
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'queue' => 'import',
                    'actor_user_id' => Auth::id(),
                    'ldap_will_change' => false,
                    'verification_only' => true,
                ],
            ]);

            PostApplyVerifyImportLdapJob::dispatch(
                operationJobId: $operationJob->id,
                importApplyPlanId: $plan->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_post_apply_verify_ldap_entries',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'ldap_will_change' => false,
                    'verification_only' => true,
                    'queue' => 'import',
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Post-apply verification queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_post_apply_verify_ldap_entries',
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
