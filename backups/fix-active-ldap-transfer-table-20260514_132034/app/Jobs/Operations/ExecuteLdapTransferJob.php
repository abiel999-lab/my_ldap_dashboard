<?php

namespace App\Jobs\Operations;

use App\Models\Operations\LdapTransferBatch;
use App\Support\Operations\LdapTransferExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecuteLdapTransferJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $batchId,
        public string $operation = 'preview',
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(LdapTransferExecutor $executor): void
    {
        $this->markRunning();

        $batch = LdapTransferBatch::query()->findOrFail($this->batchId);

        try {
            $batch->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'command_execution_id' => $this->commandExecutionId,
            ]);

            $result = $this->operation === 'preview'
                ? $executor->preview($batch)
                : $executor->execute($batch);

            if (! ($result['ok'] ?? false)) {
                $message = trim((string) ($result['stderr'] ?? '')) ?: 'LDAP transfer failed.';

                $this->markFailed($message, $result);

                throw new \RuntimeException($message);
            }

            $this->markSuccess($result);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->markFailed($e->getMessage(), [
                'operation' => $this->operation,
                'batch_id' => $this->batchId,
                'exception' => get_class($e),
            ]);

            throw $e;
        }
    }

    private function markRunning(): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'running',
                'updated_at' => now(),
            ]);
    }

    private function markSuccess(array $payload): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'success',
                'stdout' => $payload['stdout'] ?? json_encode($payload),
                'stderr' => $payload['stderr'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 0,
                'error_message' => null,
                'environment_context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markFailed(string $message, array $payload): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'failed',
                'stdout' => $payload['stdout'] ?? null,
                'stderr' => $payload['stderr'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 1,
                'error_message' => $message,
                'environment_context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
