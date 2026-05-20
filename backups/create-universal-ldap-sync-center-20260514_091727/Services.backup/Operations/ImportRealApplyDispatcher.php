<?php

namespace App\Services\Operations;

use App\Jobs\Operations\RealApplyImportLdapJob;
use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\OperationJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportRealApplyDispatcher
{
    public function queueRealApply(ImportApplyPlan $plan, string $confirmation): array
    {
        try {
            $plan->refresh();

            if ($confirmation !== 'APPLY LDAP') {
                return [
                    'ok' => false,
                    'message' => 'Confirmation must exactly be: APPLY LDAP',
                    'operation_job' => null,
                ];
            }

            if (false) {
            // Disabled old import real-apply blocker.
            // New simplified flow:
            // Import -> Preview -> Apply Plan -> Real Apply.
            // No approval / dry-run-verified gate is required anymore.
        }

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'real_import_apply_ldapadd',
                'type' => 'real_import_apply_ldapadd',
                'name' => 'REAL LDAP Apply - '.$plan->name,
                'module' => 'operations.import',
                'operation_action' => 'real_import_apply_ldapadd',
                'action' => 'real_import_apply_ldapadd',
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
                    'action' => 'real_import_apply_ldapadd',
                    'target_type' => ImportApplyPlan::class,
                    'target_key' => (string) $plan->id,
                    'target_dn' => $plan->importBatch?->base_dn,
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'queue' => 'import',
                    'actor_user_id' => Auth::id(),
                    'ldap_will_change' => true,
                    'confirmation' => $confirmation,
                ],
            ]);

            RealApplyImportLdapJob::dispatch(
                operationJobId: $operationJob->id,
                importApplyPlanId: $plan->id,
                confirmation: $confirmation,
            );

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_real_import_apply_ldapadd',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'import_apply_plan_id' => $plan->id,
                    'import_batch_id' => $plan->import_batch_id,
                    'manual_confirmation' => $confirmation,
                    'ldap_will_change' => true,
                    'queue' => 'import',
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Real LDAP apply queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'queue_real_import_apply_ldapadd',
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
