<?php

namespace App\Jobs\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\OperationJob;
use App\Services\Operations\LdapRealApplyExecutor;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RealApplyImportLdapJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public int $operationJobId,
        public int $importApplyPlanId,
        public string $confirmation,
    ) {
        $this->onQueue('import');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapRealApplyExecutor $executor,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $plan = ImportApplyPlan::query()->findOrFail($this->importApplyPlanId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => ImportApplyPlan::class,
            'target_identifier' => $plan->name,
            'target_dn' => $plan->importBatch?->base_dn,
            'action' => 'real_import_apply_ldapadd',
            'status' => 'running',
            'input_payload' => [
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'manual_confirmation' => $this->confirmation,
                'ldap_will_change' => true,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $plan->id,
                $plan->output_path,
                $plan->output_hash,
                $plan->approval_status,
                $plan->dry_run_verified_at?->toDateTimeString(),
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'warning', 'Running REAL LDAP apply using ldapadd via queue.', [
            'import_apply_plan_id' => $plan->id,
            'import_batch_id' => $plan->import_batch_id,
            'queue' => 'import',
            'ldap_will_change' => true,
        ], $item);

        $execution = $executor->apply($plan, $this->confirmation);

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
                    'ldap_was_changed' => false,
                ],
                'error_message' => $execution->error_message ?: $execution->stderr ?: 'Real LDAP apply failed.',
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $execution->error_message ?: 'Real LDAP apply failed.', [
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

        $cacheReconcile = $this->reconcileLdapCacheAfterImport(
            plan: $plan,
            tracker: $tracker,
            operationJob: $operationJob,
            item: $item,
        );

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'import_apply_plan_id' => $plan->id,
                'command_execution_id' => $execution->id,
                'status' => $execution->status,
                'exit_code' => $execution->exit_code,
                'real_apply_finished_at' => $plan->real_apply_finished_at?->toDateTimeString(),
                'ldap_was_changed' => true,
                'cache_reconcile' => $cacheReconcile,
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
                'real_apply_finished_at' => $plan->real_apply_finished_at?->toDateTimeString(),
                'ldap_was_changed' => true,
                'cache_reconcile' => $cacheReconcile,
            ],
        ]);
    }

    private function reconcileLdapCacheAfterImport(
        ImportApplyPlan $plan,
        OperationJobTracker $tracker,
        OperationJob $operationJob,
        mixed $item = null,
    ): array {
        $connectionId = $plan->importBatch?->ldap_connection_id
            ?? $plan->ldap_connection_id
            ?? null;

        $arguments = [
            '--users' => '1',
            '--objects' => '1',
            '--reason' => 'after_real_import_apply_plan_'.$plan->id,
        ];

        if ($connectionId) {
            $arguments['--connection'] = (string) $connectionId;
        }

        try {
            $exitCode = Artisan::call('iam:reconcile-ldap-cache', $arguments);
            $output = trim(Artisan::output());

            $payload = [
                'ok' => $exitCode === 0,
                'exit_code' => $exitCode,
                'connection_id' => $connectionId,
                'command' => 'iam:reconcile-ldap-cache',
                'arguments' => $arguments,
                'output' => mb_substr($output, 0, 4000),
            ];

            $tracker->log(
                $operationJob,
                $exitCode === 0 ? 'info' : 'warning',
                $exitCode === 0
                    ? 'LDAP cache reconciliation completed after real import apply.'
                    : 'LDAP cache reconciliation returned non-zero exit code after real import apply.',
                $payload,
                $item
            );

            return $payload;
        } catch (Throwable $e) {
            report($e);

            $payload = [
                'ok' => false,
                'connection_id' => $connectionId,
                'command' => 'iam:reconcile-ldap-cache',
                'arguments' => $arguments,
                'error' => $e->getMessage(),
            ];

            $tracker->log(
                $operationJob,
                'warning',
                'LDAP cache reconciliation failed after real import apply.',
                $payload,
                $item
            );

            return $payload;
        }
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);
        $plan = ImportApplyPlan::query()->find($this->importApplyPlanId);

        if ($plan) {
            $plan->forceFill([
                'status' => 'apply_failed',
                'real_apply_error_message' => $exception->getMessage(),
                'message' => 'Real LDAP apply failed.',
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
