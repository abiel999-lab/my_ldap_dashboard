<?php

namespace App\Jobs\Directory;

use App\Models\Operations\CommandExecution;
use App\Services\Directory\LdapUserLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateLdapUserJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public array $payload,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(LdapUserLifecycleService $service): void
    {
        $execution = $this->commandExecution();

        $execution?->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $service->createUser($this->payload);

            $execution?->update([
                'status' => ($result['ok'] ?? false) ? 'success' : 'failed',
                'exit_code' => ($result['ok'] ?? false) ? 0 : 1,
                'stdout' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'stderr' => ($result['ok'] ?? false) ? null : ($result['message'] ?? 'Create LDAP user failed.'),
                'error_message' => ($result['ok'] ?? false) ? null : ($result['message'] ?? 'Create LDAP user failed.'),
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
