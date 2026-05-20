<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncLdapSchemaEntriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public ?int $ldapConnectionId = null,
        public bool $reset = false,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        $this->markRunning();

        try {
            $connections = $this->ldapConnectionId
                ? LdapConnection::query()->whereKey($this->ldapConnectionId)->get()
                : LdapConnection::query()
                    ->where(function ($query): void {
                        $query->where('is_active', true)
                            ->orWhere('active', true)
                            ->orWhere('enabled', true);
                    })
                    ->get();

            if ($connections->isEmpty()) {
                $connections = LdapConnection::query()->get();
            }

            $outputs = [];
            $exitCode = 0;

            foreach ($connections as $connection) {
                Artisan::call('iam:schema-sync-direct', [
                    '--connection' => (string) $connection->id,
                    '--reset' => $this->reset ? '1' : '0',
                ]);

                $outputs[] = [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name ?? null,
                    'output' => Artisan::output(),
                ];
            }

            $counts = DB::table('ldap_schema_entries')
                ->select('schema_type', DB::raw('count(*) as total'))
                ->groupBy('schema_type')
                ->orderBy('schema_type')
                ->get()
                ->toArray();

            $payload = [
                'operation' => 'sync_ldap_schema',
                'ldap_connection_id' => $this->ldapConnectionId,
                'reset' => $this->reset,
                'connections_processed' => $connections->pluck('id')->values()->all(),
                'counts' => $counts,
                'outputs' => $outputs,
                'exit_code' => $exitCode,
            ];

            $this->markSuccess($payload);
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage(), [
                'operation' => 'sync_ldap_schema',
                'ldap_connection_id' => $this->ldapConnectionId,
                'reset' => $this->reset,
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
                'stdout' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'stderr' => null,
                'exit_code' => 0,
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
                'stdout' => null,
                'stderr' => null,
                'exit_code' => 1,
                'error_message' => $message,
                'environment_context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
