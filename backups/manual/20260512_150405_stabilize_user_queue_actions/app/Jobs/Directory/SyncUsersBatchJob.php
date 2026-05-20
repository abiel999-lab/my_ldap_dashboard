<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Directory\LdapSingleUserSyncService;
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
        $execution = $this->commandExecution();

        $ok = 0;
        $failed = 0;
        $results = [];

        $execution?->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            LdapUserEntry::query()
                ->whereIn('id', $this->userIds)
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($syncService, &$ok, &$failed, &$results): void {
                    foreach ($users as $user) {
                        $result = $syncService->sync($user);

                        $results[] = [
                            'user_id' => $user->id,
                            'dn' => $user->dn,
                            'ok' => (bool) ($result['ok'] ?? false),
                            'message' => $result['message'] ?? null,
                            'command_execution_id' => $result['command_execution_id'] ?? null,
                        ];

                        if ($result['ok'] ?? false) {
                            $ok++;
                        } else {
                            $failed++;
                        }
                    }
                });

            $execution?->update([
                'status' => $failed > 0 ? 'partial_success' : 'success',
                'exit_code' => $failed > 0 ? 1 : 0,
                'stdout' => json_encode([
                    'success' => $ok,
                    'failed' => $failed,
                    'total' => count($this->userIds),
                    'results' => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'stderr' => $failed > 0 ? 'Some users failed to sync. See stdout JSON results.' : null,
                'error_message' => $failed > 0 ? 'Partial sync failure.' : null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $execution?->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stderr' => $e->getMessage(),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    private function commandExecution(): ?CommandExecution
    {
        if (! $this->commandExecutionId) {
            return null;
        }

        return CommandExecution::query()->find($this->commandExecutionId);
    }
}
