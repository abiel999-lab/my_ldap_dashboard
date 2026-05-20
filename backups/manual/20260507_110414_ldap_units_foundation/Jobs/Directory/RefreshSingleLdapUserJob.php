<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\OperationJob;
use App\Services\Directory\LdapSingleUserRefreshService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshSingleLdapUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $operationJobId,
        public int $ldapUserEntryId,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapSingleUserRefreshService $refreshService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $userEntry = LdapUserEntry::query()->findOrFail($this->ldapUserEntryId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => LdapUserEntry::class,
            'target_identifier' => $userEntry->uid ?: $userEntry->cn ?: $userEntry->dn,
            'target_dn' => $userEntry->dn,
            'action' => 'refresh_single_ldap_user',
            'status' => 'running',
            'input_payload' => [
                'ldap_user_entry_id' => $userEntry->id,
                'ldap_connection_id' => $userEntry->ldap_connection_id,
                'read_only' => true,
                'ldap_will_change' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                'refresh_single_ldap_user',
                $userEntry->id,
                $userEntry->dn,
                $userEntry->source_hash,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Refreshing single LDAP user from LDAP source.', [
            'ldap_user_entry_id' => $userEntry->id,
            'target_dn' => $userEntry->dn,
            'queue' => 'ldap',
            'ldap_will_change' => false,
        ], $item);

        $result = $refreshService->refresh($userEntry);

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
