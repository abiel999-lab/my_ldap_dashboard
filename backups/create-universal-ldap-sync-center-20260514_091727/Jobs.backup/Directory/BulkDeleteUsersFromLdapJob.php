<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapUserEntry;
use App\Services\Directory\LdapUserLifecycleService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BulkDeleteUsersFromLdapJob implements ShouldQueue
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

    public function handle(LdapUserLifecycleService $service): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        $ok = 0;
        $failed = 0;
        $results = [];

        try {
            LdapUserEntry::query()
                ->whereIn('id', $this->userIds)
                ->orderBy('id')
                ->chunkById(25, function ($users) use ($service, &$ok, &$failed, &$results): void {
                    foreach ($users as $user) {
                        $dn = $user->dn;

                        try {
                            $result = $service->deleteUser($user);

                            $results[] = [
                                'user_id' => $user->id,
                                'dn' => $dn,
                                'ok' => (bool) ($result['ok'] ?? false),
                                'message' => $result['message'] ?? null,
                                'child_command_execution_id' => $result['command_execution_id'] ?? null,
                            ];

                            ($result['ok'] ?? false) ? $ok++ : $failed++;
                        } catch (Throwable $e) {
                            $failed++;

                            $results[] = [
                                'user_id' => $user->id,
                                'dn' => $dn,
                                'ok' => false,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                });

            $summary = [
                'operation' => 'bulk_delete_users_from_ldap',
                'total' => count($this->userIds),
                'success' => $ok,
                'failed' => $failed,
                'results' => $results,
            ];

            if ($failed > 0) {
                SafeCommandExecutionLogger::markPartial($this->commandExecutionId, $summary, 'Some users failed to delete.', $summary);
                return;
            }

            SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $summary, $summary);
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed($this->commandExecutionId, $e->getMessage(), [
                'operation' => 'bulk_delete_users_from_ldap',
                'user_ids' => $this->userIds,
            ]);

            throw $e;
        }
    }
}
