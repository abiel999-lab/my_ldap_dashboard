<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Support\Directory\LdapSchemaWriteExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ModifyLdapSchemaDefinitionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $ldapConnectionId,
        public string $operation,
        public string $schemaType,
        public string $schemaConfigDn,
        public string $definition,
        public ?string $oldDefinition = null,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(LdapSchemaWriteExecutor $executor): void
    {
        $this->markRunning();

        try {
            $connection = LdapConnection::query()->findOrFail($this->ldapConnectionId);

            $result = $executor->execute(
                $connection,
                $this->operation,
                $this->schemaType,
                $this->schemaConfigDn,
                $this->definition,
                $this->oldDefinition
            );

            if (! ($result['ok'] ?? false)) {
                $message = trim((string) ($result['stderr'] ?? '')) ?: 'LDAP schema operation failed.';

                $this->markFailed($message, $result);

                throw new \RuntimeException($message);
            }

            $this->markSuccess($result);

            try {
                \Artisan::call('iam:schema-sync-direct', [
                    '--connection' => (string) $this->ldapConnectionId,
                    '--reset' => '1',
                ]);
            } catch (Throwable $syncError) {
                report($syncError);
            }
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage(), [
                'operation' => $this->operation,
                'schema_type' => $this->schemaType,
                'schema_config_dn' => $this->schemaConfigDn,
                'definition' => $this->definition,
                'old_definition' => $this->oldDefinition,
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
                'stdout' => $payload['stdout'] ?? null,
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
