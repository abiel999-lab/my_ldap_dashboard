<?php

namespace App\Jobs\Directory;

use App\Models\Operations\OperationJob;
use App\Services\Directory\LdapUnitSyncService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncLdapUnitsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapUnitSyncService $syncService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => 'ldap_group_entries',
            'target_identifier' => 'organizational_units',
            'target_dn' => null,
            'action' => 'sync_ldap_units_from_group_cache',
            'status' => 'running',
            'input_payload' => [
                'source' => 'ldap_group_entries',
                'read_only' => true,
                'ldap_will_change' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                'sync_ldap_units_from_group_cache',
                now()->toDateString(),
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Syncing LDAP units from cached LDAP organizational units.', [
            'source' => 'ldap_group_entries',
            'queue' => 'ldap',
            'ldap_will_change' => false,
        ], $item);

        $result = $syncService->sync();

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
                'metadata' => $result,
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => $result,
            'finished_at' => now(),
        ]);

        $tracker->markSuccess($operationJob, [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'metadata' => $result,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);

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
