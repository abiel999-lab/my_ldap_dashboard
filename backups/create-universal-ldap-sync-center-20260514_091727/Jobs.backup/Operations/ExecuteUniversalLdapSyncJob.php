<?php

namespace App\Jobs\Operations;

use App\Models\Operations\LdapSyncBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\UniversalLdapSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteUniversalLdapSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $operationJobId,
        public int $ldapSyncBatchId,
    ) {
        $this->onQueue('operations');
    }

    public function handle(UniversalLdapSyncService $service): void
    {
        $job = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = LdapSyncBatch::query()->findOrFail($this->ldapSyncBatchId);

        $job->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        $batch->forceFill([
            'status' => 'running',
            'operation_job_id' => $job->id,
            'started_at' => now(),
        ])->save();

        $result = $service->sync($batch, $job);

        if (! $result['ok']) {
            $batch->forceFill([
                'status' => 'failed',
                'message' => $result['message'],
                'finished_at' => now(),
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'message' => $result['message'],
                'failed_items' => 1,
                'finished_at' => now(),
            ])->save();

            return;
        }

        $batch->forceFill([
            'status' => 'success',
            'message' => $result['message'],
            'total_entries' => $result['total_entries'],
            'created_entries' => $result['created_entries'],
            'updated_entries' => $result['updated_entries'],
            'failed_entries' => $result['failed_entries'],
            'finished_at' => now(),
        ])->save();

        $job->forceFill([
            'status' => 'success',
            'total_items' => $result['total_entries'],
            'processed_items' => $result['total_entries'],
            'success_items' => $result['created_entries'] + $result['updated_entries'],
            'failed_items' => $result['failed_entries'],
            'finished_at' => now(),
            'metadata' => array_merge((array) $job->metadata, $result),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        LdapSyncBatch::query()
            ->whereKey($this->ldapSyncBatchId)
            ->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

        OperationJob::query()
            ->whereKey($this->operationJobId)
            ->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
