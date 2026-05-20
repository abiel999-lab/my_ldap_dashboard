<?php

namespace App\Jobs\Ldap;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\OperationJob;
use App\Services\Ldap\LdapDirectoryBrowserService;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshLdapDirectoryCacheJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $operationJobId,
        public int $ldapConnectionId,
        public string $baseDn,
        public string $filter = '(objectClass=*)',
        public int $limit = 200,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapDirectoryBrowserService $browserService,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $connection = LdapConnection::query()->findOrFail($this->ldapConnectionId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => LdapConnection::class,
            'target_identifier' => $connection->name,
            'target_dn' => $this->baseDn,
            'action' => 'refresh_ldap_directory_cache',
            'status' => 'running',
            'input_payload' => [
                'ldap_connection_id' => $connection->id,
                'connection_name' => $connection->name,
                'base_dn' => $this->baseDn,
                'filter' => $this->filter,
                'limit' => $this->limit,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $connection->id,
                $this->baseDn,
                $this->filter,
                $this->limit,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Refreshing LDAP directory cache.', [
            'ldap_connection_id' => $connection->id,
            'base_dn' => $this->baseDn,
            'filter' => $this->filter,
            'limit' => $this->limit,
        ], $item);

        $result = $browserService->refreshCache(
            connection: $connection,
            baseDn: $this->baseDn,
            filter: $this->filter,
            limit: $this->limit,
        );

        if (! $result['ok']) {
            $tracker->updateItem($item, [
                'status' => 'failed',
                'error_message' => $result['message'],
                'output_payload' => $result,
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $result['message'], [
                'processed_items' => 1,
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
            'processed_items' => 1,
            'failed_items' => 1,
        ]);
    }
}
