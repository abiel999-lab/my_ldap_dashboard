<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Services\Directory\LdapUserSyncService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncLdapUsersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public int $operationJobId,
        public ?int $ldapConnectionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapUserSyncService $syncService,
    ): void {
        $operationJob = \App\Models\Operations\OperationJob::query()->findOrFail($this->operationJobId);
        $connection = $this->ldapConnectionId ? LdapConnection::query()->find($this->ldapConnectionId) : null;

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => LdapConnection::class,
            'target_identifier' => $connection?->name ?? 'default',
            'target_dn' => $connection?->base_dn,
            'action' => 'sync_ldap_users',
            'status' => 'running',
            'input_payload' => [
                'ldap_connection_id' => $connection?->id,
                'read_only' => true,
                'ldap_will_change' => false,
            ],
            'payload_hash' => hash('sha256', json_encode([
                'sync_ldap_users',
                $connection?->id,
                $connection?->base_dn,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Syncing LDAP users into local index.', [
            'ldap_connection_id' => $connection?->id,
            'ldap_connection_name' => $connection?->name,
            'queue' => 'ldap',
            'ldap_will_change' => false,
        ], $item);

        $result = $syncService->sync($connection);

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
        $operationJob = \App\Models\Operations\OperationJob::query()->find($this->operationJobId);

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
