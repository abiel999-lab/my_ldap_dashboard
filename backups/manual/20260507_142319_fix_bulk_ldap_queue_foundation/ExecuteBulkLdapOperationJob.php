<?php

namespace App\Jobs\Operations;

use App\Models\Operations\BulkLdapOperation;
use App\Services\Operations\BulkLdapUserOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;

class ExecuteBulkLdapOperationJob implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

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
}
