<?php

namespace App\Jobs\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\OperationJob;
use App\Services\Operations\LdapApplyDryRunVerifier;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class VerifyImportApplyLdapDryRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
        public int $importApplyPlanId,
    ) {
        $this->onQueue('import');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapApplyDryRunVerifier $verifier,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $plan = ImportApplyPlan::query()->findOrFail($this->importApplyPlanId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => ImportApplyPlan::class,
            'target_identifier' => $plan->name,
            'target_dn' => $plan->importBatch?->base_dn,
            'action' => 'verify_import_apply_ldapadd_dry_run',
            'status' => 'running',
            'input_payload' => [
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'ldap_will_change' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $plan->id,
                $plan->output_path,
                $plan->output_hash,
                $plan->approval_status,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Verifying import apply LDIF using ldapadd dry-run via queue.', [
            'import_apply_plan_id' => $plan->id,
            'import_batch_id' => $plan->import_batch_id,
            'queue' => 'import',
            'ldap_will_change' => false,
        ], $item);

        $execution = $verifier->verify($plan);

        $execution->forceFill([
            'operation_job_id' => $operationJob->id,
            'operation_job_item_id' => $item?->id,
        ])->save();

        $plan->refresh();

        if ($execution->status !== 'success') {
            $tracker->updateItem($item, [
                'status' => 'failed',
                'output_payload' => [
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                    'error_message' => $execution->error_message,
                ],
                'error_message' => $execution->error_message ?: $execution->stderr ?: 'ldapadd dry-run verification failed.',
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $execution->error_message ?: 'ldapadd dry-run verification failed.', [
                'total_items' => 1,
                'processed_items' => 1,
                'success_items' => 0,
                'failed_items' => 1,
                'metadata' => [
                    'import_apply_plan_id' => $plan->id,
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                    'ldap_was_changed' => false,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'import_apply_plan_id' => $plan->id,
                'command_execution_id' => $execution->id,
                'status' => $execution->status,
                'exit_code' => $execution->exit_code,
                'dry_run_verified_at' => $plan->dry_run_verified_at?->toDateTimeString(),
                'ldap_was_changed' => false,
            ],
            'finished_at' => now(),
        ]);

        $tracker->markSuccess($operationJob, [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'metadata' => [
                'import_apply_plan_id' => $plan->id,
                'command_execution_id' => $execution->id,
                'dry_run_verified_at' => $plan->dry_run_verified_at?->toDateTimeString(),
                'ldap_was_changed' => false,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);
        $plan = ImportApplyPlan::query()->find($this->importApplyPlanId);

        if ($plan) {
            $plan->forceFill([
                'status' => 'dry_run_failed',
                'dry_run_error_message' => $exception->getMessage(),
                'message' => 'LDAP apply dry-run failed. LDAP data has not been changed.',
            ])->save();
        }

        if (! $operationJob) {
            return;
        }

        $tracker->markFailed($operationJob, $exception->getMessage(), [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 0,
            'failed_items' => 1,
        ]);
    }
}
