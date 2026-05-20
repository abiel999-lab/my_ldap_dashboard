<?php

namespace App\Jobs\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\ImportApplyPlanService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateImportApplyLdifPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
        public int $importBatchId,
        public int $importApplyPlanId,
    ) {
        $this->onQueue('import');
    }

    public function handle(
        OperationJobTracker $tracker,
        ImportApplyPlanService $planService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);
        $plan = ImportApplyPlan::query()->findOrFail($this->importApplyPlanId);

        $tracker->markRunning($operationJob);

        $plan->forceFill([
            'status' => 'running',
            'operation_job_id' => $operationJob->id,
            'started_at' => now(),
        ])->save();

        $item = $tracker->createItem($operationJob, [
            'target_type' => ImportBatch::class,
            'target_identifier' => $batch->name,
            'target_dn' => $batch->base_dn,
            'action' => 'generate_import_apply_ldif_dry_run',
            'status' => 'running',
            'input_payload' => [
                'import_batch_id' => $batch->id,
                'import_apply_plan_id' => $plan->id,
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $batch->id,
                $plan->id,
                $batch->updated_at?->toDateTimeString(),
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Generating import apply LDIF dry run via queue.', [
            'import_batch_id' => $batch->id,
            'import_apply_plan_id' => $plan->id,
            'queue' => 'import',
        ], $item);

        $result = $planService->generate($batch, $plan);

        $plan->refresh();

        if (! $result['ok']) {
            $tracker->updateItem($item, [
                'status' => 'failed',
                'output_payload' => $result,
                'error_message' => $result['message'],
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $result['message'], [
                'total_items' => 1,
                'processed_items' => 1,
                'success_items' => 0,
                'failed_items' => 1,
                'metadata' => [
                    'import_batch_id' => $batch->id,
                    'import_apply_plan_id' => $plan->id,
                    'result' => $result,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'import_batch_id' => $batch->id,
                'import_apply_plan_id' => $plan->id,
                'output_path' => $plan->output_path,
                'output_hash' => $plan->output_hash,
            ],
            'finished_at' => now(),
        ]);

        $tracker->markSuccess($operationJob, [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'metadata' => [
                'import_batch_id' => $batch->id,
                'import_apply_plan_id' => $plan->id,
                'output_path' => $plan->output_path,
                'output_size_bytes' => $plan->output_size_bytes,
                'output_hash' => $plan->output_hash,
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
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
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
