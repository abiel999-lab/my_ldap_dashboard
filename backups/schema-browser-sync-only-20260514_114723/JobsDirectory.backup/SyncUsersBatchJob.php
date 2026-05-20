<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapUserEntry;
use App\Services\Directory\LdapSingleUserSyncService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncUsersBatchJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public array $userIds,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(LdapSingleUserSyncService $syncService): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        $ok = 0;
        $failed = 0;
        $results = [];

        try {
            LdapUserEntry::query()
                ->whereIn('id', $this->userIds)
                ->orderBy('id')
                ->chunkById(50, function ($users) use ($syncService, &$ok, &$failed, &$results): void {
                    foreach ($users as $user) {
                        try {
                            $result = $syncService->sync($user);

                            $results[] = [
                                'user_id' => $user->id,
                                'dn' => $user->dn,
                                'ok' => (bool) ($result['ok'] ?? false),
                                'message' => $result['message'] ?? null,
                                'child_command_execution_id' => $result['command_execution_id'] ?? null,
                            ];

                            ($result['ok'] ?? false) ? $ok++ : $failed++;
                        } catch (Throwable $e) {
                            $failed++;

                            $results[] = [
                                'user_id' => $user->id,
                                'dn' => $user->dn,
                                'ok' => false,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                });

            $summary = [
                'operation' => 'sync_users_batch',
                'total' => count($this->userIds),
                'success' => $ok,
                'failed' => $failed,
                'results' => $results,
            ];

            if ($failed > 0) {
                SafeCommandExecutionLogger::markPartial($this->commandExecutionId, $summary, 'Some users failed to sync.', $summary);
                return;
            }

            SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $summary, $summary);
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed($this->commandExecutionId, $e->getMessage(), [
                'operation' => 'sync_users_batch',
                'user_ids' => $this->userIds,
            ]);

            throw $e;
        }
    }
}
