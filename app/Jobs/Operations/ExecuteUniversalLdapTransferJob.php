<?php

namespace App\Jobs\Operations;

use App\Models\Operations\OperationJob;
use App\Models\Operations\UniversalLdapTransferBatch;
use App\Services\Operations\OperationJobFactory;
use App\Services\Operations\UniversalLdapTransferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteUniversalLdapTransferJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $operationJobId,
        public int $transferBatchId,
    ) {
        $this->onQueue('operations');
    }

    public function handle(
        UniversalLdapTransferService $service,
        OperationJobFactory $jobs,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = UniversalLdapTransferBatch::query()->findOrFail($this->transferBatchId);

        $jobs->markRunning($operationJob, [
            'event' => 'ldap_transfer_preview_started',
            'transfer_batch_id' => $batch->id,
            'effective_source_dn' => $batch->effective_source_dn,
            'target_parent_dn' => $batch->target_parent_dn,
        ]);

        $batch->forceFill([
            'status' => 'running',
            'operation_job_id' => $operationJob->id,
            'message' => 'LDAP transfer preview is running.',
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        try {
            $result = $service->preview($batch, $operationJob);

            if (! ($result['ok'] ?? false)) {
                $this->failBatch($batch, $operationJob, $jobs, (string) ($result['message'] ?? 'LDAP transfer preview failed.'), $result);
                return;
            }

            $total = (int) ($result['total_entries'] ?? 0);
            $planned = (int) ($result['planned_entries'] ?? 0);
            $failed = (int) ($result['failed_entries'] ?? 0);

            $batch->forceFill([
                'status' => $failed > 0 ? 'partial_success' : 'success',
                'message' => (string) ($result['message'] ?? 'LDAP transfer preview completed.'),
                'total_entries' => $total,
                'planned_entries' => $planned,
                'transferred_entries' => 0,
                'failed_entries' => $failed,
                'output_path' => $result['output_path'] ?? null,
                'output_size_bytes' => $result['output_size_bytes'] ?? null,
                'output_hash' => $result['output_hash'] ?? null,
                'finished_at' => now(),
            ])->save();

            $jobs->markSuccess($operationJob, [
                'event' => 'ldap_transfer_preview_success',
                'transfer_batch_id' => $batch->id,
                'total_entries' => $total,
                'planned_entries' => $planned,
                'transferred_entries' => 0,
                'failed_entries' => $failed,
                'processed_items' => $total,
                'success_items' => $planned,
                'failed_items' => $failed,
                'output_path' => $result['output_path'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->failBatch($batch, $operationJob, $jobs, $message, [
                'exception' => get_class($exception),
                'failed_items' => 1,
            ]);

            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        $batch = UniversalLdapTransferBatch::query()->find($this->transferBatchId);
        $operationJob = OperationJob::query()->find($this->operationJobId);

        if ($batch) {
            $batch->forceFill([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'failed_entries' => 1,
                'finished_at' => now(),
            ])->save();
        }

        if ($operationJob) {
            app(OperationJobFactory::class)->markFailed($operationJob, $exception->getMessage(), [
                'event' => 'ldap_transfer_preview_queue_failed',
                'transfer_batch_id' => $this->transferBatchId,
                'exception' => get_class($exception),
                'failed_items' => 1,
            ]);
        }
    }

    private function failBatch(
        UniversalLdapTransferBatch $batch,
        OperationJob $operationJob,
        OperationJobFactory $jobs,
        string $message,
        array $context = [],
    ): void {
        $batch->forceFill([
            'status' => 'failed',
            'message' => $message,
            'failed_entries' => max(1, (int) ($context['failed_entries'] ?? 1)),
            'finished_at' => now(),
        ])->save();

        $jobs->markFailed($operationJob, $message, array_merge($context, [
            'event' => 'ldap_transfer_preview_failed',
            'transfer_batch_id' => $batch->id,
            'failed_items' => max(1, (int) ($context['failed_items'] ?? 1)),
        ]));
    }
}
