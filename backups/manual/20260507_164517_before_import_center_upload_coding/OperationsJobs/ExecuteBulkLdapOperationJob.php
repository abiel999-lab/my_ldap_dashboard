<?php

namespace App\Jobs\Operations;

use App\Models\Operations\BulkLdapOperation;
use App\Services\Operations\BulkLdapUserOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecuteBulkLdapOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $maxExceptions = 1;

    public function __construct(
        public int $bulkLdapOperationId,
        public bool $retryOnlyFailed = false,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(BulkLdapUserOperationService $service): void
    {
        $operation = BulkLdapOperation::query()->findOrFail($this->bulkLdapOperationId);

        $service->executeQueuedOperation($operation, $this->retryOnlyFailed);
    }

    public function failed(Throwable $exception): void
    {
        $operation = BulkLdapOperation::query()->find($this->bulkLdapOperationId);

        if (! $operation) {
            return;
        }

        $operation->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'finished_at' => now(),
            'message' => 'Bulk LDAP queue job failed before completion.',
            'error_message' => $exception->getMessage(),
        ])->save();
    }
}
