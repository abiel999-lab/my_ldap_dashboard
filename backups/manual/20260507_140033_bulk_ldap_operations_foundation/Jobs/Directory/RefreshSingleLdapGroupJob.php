<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapGroupEntry;
use App\Models\Operations\OperationJob;
use App\Services\Directory\LdapSingleGroupRefreshService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshSingleLdapGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $operationJobId,
        public int $ldapGroupEntryId,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapSingleGroupRefreshService $refreshService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $groupEntry = LdapGroupEntry::query()->findOrFail($this->ldapGroupEntryId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => LdapGroupEntry::class,
            'target_identifier' => $groupEntry->cn ?: $groupEntry->ou ?: $groupEntry->dn,
            'target_dn' => $groupEntry->dn,
            'action' => 'refresh_single_ldap_group',
            'status' => 'running',
            'input_payload' => [
                'ldap_group_entry_id' => $groupEntry->id,
                'ldap_connection_id' => $groupEntry->ldap_connection_id,
                'read_only' => true,
                'ldap_will_change' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                'refresh_single_ldap_group',
                $groupEntry->id,
                $groupEntry->dn,
                $groupEntry->source_hash,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Refreshing single LDAP group from LDAP source.', [
            'ldap_group_entry_id' => $groupEntry->id,
            'target_dn' => $groupEntry->dn,
            'queue' => 'ldap',
            'ldap_will_change' => false,
        ], $item);

        $result = $refreshService->refresh($groupEntry);

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
