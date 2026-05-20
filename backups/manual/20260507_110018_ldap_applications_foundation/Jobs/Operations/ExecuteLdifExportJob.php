<?php

namespace App\Jobs\Operations;

use App\Models\Operations\LdifExportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\LdifExportExecutor;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteLdifExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
        public int $ldifExportBatchId,
    ) {
        $this->onQueue('export');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdifExportExecutor $executor,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = LdifExportBatch::query()->findOrFail($this->ldifExportBatchId);

        $tracker->markRunning($operationJob);

        $batch->forceFill([
            'status' => 'running',
            'operation_job_id' => $operationJob->id,
            'started_at' => now(),
        ])->save();

        $item = $tracker->createItem($operationJob, [
            'target_type' => LdifExportBatch::class,
            'target_identifier' => $batch->name,
            'target_dn' => $batch->base_dn,
            'action' => 'execute_ldif_export',
            'status' => 'running',
            'input_payload' => [
                'ldif_export_batch_id' => $batch->id,
                'base_dn' => $batch->base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $batch->id,
                $batch->base_dn,
                $batch->filter,
                $batch->attributes,
                $batch->size_limit,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Executing LDIF export via queue.', [
            'ldif_export_batch_id' => $batch->id,
            'name' => $batch->name,
            'base_dn' => $batch->base_dn,
            'filter' => $batch->filter,
            'queue' => 'export',
        ], $item);

        $execution = $executor->execute($batch);

        $execution->forceFill([
            'operation_job_id' => $operationJob->id,
            'operation_job_item_id' => $item?->id,
        ])->save();

        $batch->refresh();

        if ($execution->status !== 'success') {
            $tracker->updateItem($item, [
                'status' => 'failed',
                'output_payload' => [
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                    'error_message' => $execution->error_message,
                ],
                'error_message' => $execution->error_message ?: $execution->stderr ?: 'LDIF export failed.',
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $execution->error_message ?: 'LDIF export failed.', [
                'total_items' => 1,
                'processed_items' => 1,
                'success_items' => 0,
                'failed_items' => 1,
                'metadata' => [
                    'ldif_export_batch_id' => $batch->id,
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'ldif_export_batch_id' => $batch->id,
                'command_execution_id' => $execution->id,
                'output_path' => $batch->output_path,
                'output_size_bytes' => $batch->output_size_bytes,
                'output_hash' => $batch->output_hash,
            ],
            'finished_at' => now(),
        ]);

        $tracker->markSuccess($operationJob, [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'metadata' => [
                'ldif_export_batch_id' => $batch->id,
                'command_execution_id' => $execution->id,
                'output_path' => $batch->output_path,
                'output_size_bytes' => $batch->output_size_bytes,
                'output_hash' => $batch->output_hash,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);
        $batch = LdifExportBatch::query()->find($this->ldifExportBatchId);

        if ($batch) {
            $batch->forceFill([
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
