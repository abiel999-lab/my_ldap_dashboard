<?php

namespace App\Jobs\Operations;

use App\Models\Operations\LdapSyncBatch;
use App\Models\Operations\OperationJob;
use App\Services\Operations\OperationJobFactory;
use App\Services\Operations\UniversalLdapSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Schema;
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

    public function handle(
        UniversalLdapSyncService $service,
        OperationJobFactory $jobs,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $batch = LdapSyncBatch::query()->findOrFail($this->ldapSyncBatchId);

        $jobs->markRunning($operationJob, [
            'event' => 'ldap_sync_started',
            'ldap_sync_batch_id' => $batch->id,
            'effective_base_dn' => $batch->effective_base_dn,
            'filter' => $batch->filter,
        ]);

        $batch->forceFill([
            'status' => 'running',
            'operation_job_id' => $operationJob->id,
            'message' => 'LDAP sync is running.',
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        try {
            $result = $service->sync($batch, $operationJob);

            if (! ($result['ok'] ?? false)) {
                $message = (string) ($result['message'] ?? 'Universal LDAP sync failed.');

                $batch->forceFill([
                    'status' => 'failed',
                    'message' => $message,
                    'failed_entries' => max(1, (int) ($result['failed_entries'] ?? 1)),
                    'finished_at' => now(),
                ])->save();

                $jobs->markFailed($operationJob, $message, [
                    'event' => 'ldap_sync_failed',
                    'ldap_sync_batch_id' => $batch->id,
                    'processed_items' => (int) ($result['total_entries'] ?? 0),
                    'failed_items' => max(1, (int) ($result['failed_entries'] ?? 1)),
                ]);

                return;
            }

            $total = (int) ($result['total_entries'] ?? 0);
            $created = (int) ($result['created_entries'] ?? 0);
            $updated = (int) ($result['updated_entries'] ?? 0);
            $failed = (int) ($result['failed_entries'] ?? 0);

            $batch->forceFill([
                'status' => $failed > 0 ? 'partial_success' : 'success',
                'message' => (string) ($result['message'] ?? 'Universal LDAP sync completed.'),
                'total_entries' => $total,
                'created_entries' => $created,
                'updated_entries' => $updated,
                'failed_entries' => $failed,
                'finished_at' => now(),
            ])->save();

            $jobs->markSuccess($operationJob, [
                'event' => 'ldap_sync_success',
                'ldap_sync_batch_id' => $batch->id,
                'total_entries' => $total,
                'created_entries' => $created,
                'updated_entries' => $updated,
                'failed_entries' => $failed,
                'processed_items' => $total,
                'success_items' => $created + $updated,
                'failed_items' => $failed,
            ]);
        } catch (Throwable $exception) {
            $debugMessage = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->markBatchFailed($debugMessage);

            $jobs->markFailed($operationJob, $debugMessage, [
                'event' => 'ldap_sync_exception',
                'ldap_sync_batch_id' => $batch->id,
                'exception' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'failed_items' => 1,
            ]);

            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markBatchFailed($exception->getMessage());

        $operationJob = OperationJob::query()->find($this->operationJobId);

        if ($operationJob) {
            app(OperationJobFactory::class)->markFailed($operationJob, $exception->getMessage(), [
                'event' => 'ldap_sync_queue_failed',
                'ldap_sync_batch_id' => $this->ldapSyncBatchId,
                'exception' => get_class($exception),
                'failed_items' => 1,
            ]);
        }
    }

    private function markBatchFailed(string $message): void
    {
        $batch = LdapSyncBatch::query()->find($this->ldapSyncBatchId);

        if (! $batch) {
            return;
        }

        $batch->forceFill([
            'status' => 'failed',
            'message' => $message,
            'failed_entries' => max(1, (int) ($batch->failed_entries ?? 0)),
            'finished_at' => now(),
        ])->save();
    }
}
