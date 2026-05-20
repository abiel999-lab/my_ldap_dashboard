<?php

namespace App\Jobs\Operations;

use App\Models\Operations\ImportBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\ImportPreviewService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PreviewImportBatchJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
            'message' => 'Import preview is running via queue.',
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
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
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

        $summary = $result['summary'] ?? [
            'import_batch_id' => $batch->id,
            'name' => $batch->name,
            'import_type' => $batch->import_type,
            'status' => $batch->status,
            'total_rows' => (int) $batch->total_rows,
            'valid_rows' => (int) $batch->valid_rows,
            'invalid_rows' => (int) $batch->invalid_rows,
            'duplicate_rows' => (int) $batch->duplicate_rows,
            'conflict_rows' => (int) $batch->conflict_rows,
            'will_create_rows' => (int) $batch->will_create_rows,
            'will_update_rows' => (int) $batch->will_update_rows,
            'will_skip_rows' => (int) $batch->will_skip_rows,
            'will_fail_rows' => (int) $batch->will_fail_rows,
            'message' => $batch->message,
        ];

        if (! ($result['ok'] ?? false)) {
            $errorMessage = $result['message'] ?? 'Import preview failed.';

            $tracker->updateItem($item, [
                'status' => 'failed',
                'output_payload' => [
                    'result' => $result,
                    'summary' => $summary,
                ],
                'error_message' => $errorMessage,
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $errorMessage, [
                'total_items' => 1,
                'processed_items' => 1,
                'success_items' => 0,
                'failed_items' => 1,
                'metadata' => [
                    'import_batch_id' => $batch->id,
                    'result' => $result,
                    'summary' => $summary,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'import_batch_id' => $batch->id,
                'result' => $result,
                'summary' => $summary,
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
                'summary' => $summary,
            ],
        ]);

        $batch->forceFill([
            'operation_job_id' => $operationJob->id,
            'message' => $batch->message ?: 'Import preview completed via queue.',
        ])->save();
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
