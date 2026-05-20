<?php

namespace App\Jobs\Operations;

use App\Models\Operations\ImportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\ImportPreviewService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PreviewImportBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
        public int $importBatchId,
    ) {
        $this->onQueue('import');
    }

    public function handle(
        OperationJobTracker $tracker,
        ImportPreviewService $previewService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $tracker->markRunning($operationJob);

        $batch->forceFill([
            'status' => 'previewing',
            'operation_job_id' => $operationJob->id,
            'preview_started_at' => now(),
        ])->save();

        $item = $tracker->createItem($operationJob, [
            'target_type' => ImportBatch::class,
            'target_identifier' => $batch->name,
            'target_dn' => $batch->base_dn,
            'action' => 'preview_import',
            'status' => 'running',
            'input_payload' => [
                'import_batch_id' => $batch->id,
                'import_type' => $batch->import_type,
                'file_path' => $batch->file_path,
                'base_dn' => $batch->base_dn,
                'identifier_attribute' => $batch->identifier_attribute,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $batch->id,
                $batch->import_type,
                $batch->file_path,
                $batch->base_dn,
                $batch->identifier_attribute,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Previewing import batch via queue.', [
            'import_batch_id' => $batch->id,
            'name' => $batch->name,
            'import_type' => $batch->import_type,
            'queue' => 'import',
        ], $item);

        $result = $previewService->preview($batch);

        $batch->refresh();

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
                    'result' => $result,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'import_batch_id' => $batch->id,
                'summary' => $result['summary'],
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
                'summary' => $result['summary'],
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);
        $batch = ImportBatch::query()->find($this->importBatchId);

        if ($batch) {
            $batch->forceFill([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'preview_finished_at' => now(),
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
