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
use Illuminate\Support\Facades\Schema;
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
        public bool $reset = true,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        $this->markRunning();

        try {
            $connections = $this->resolveConnections();

            if ($connections->isEmpty()) {
                throw new \RuntimeException('No LDAP connection available for schema sync.');
            }

            $outputs = [];

            foreach ($connections as $connection) {
                Artisan::call('iam:schema-sync-direct', [
                    '--connection' => (string) $connection->id,
                    '--reset' => $this->reset ? '1' : '0',
                ]);

                $outputs[] = [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name ?? null,
                    'reset' => $this->reset,
                    'output' => Artisan::output(),
                ];
            }

            $countsQuery = DB::table('ldap_schema_entries')
                ->select('schema_type', DB::raw('count(*) as total'));

            if ($this->ldapConnectionId && Schema::hasColumn('ldap_schema_entries', 'ldap_connection_id')) {
                $countsQuery->where('ldap_connection_id', $this->ldapConnectionId);
            }

            $counts = $countsQuery
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
                'exit_code' => 0,
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

    private function resolveConnections()
    {
        if ($this->ldapConnectionId) {
            return LdapConnection::query()
                ->whereKey($this->ldapConnectionId)
                ->get();
        }

        $query = LdapConnection::query();

        $hasAnyActiveColumn = false;

        $query->where(function ($query) use (&$hasAnyActiveColumn): void {
            foreach (['is_active', 'active', 'enabled'] as $column) {
                if (Schema::hasColumn('ldap_connections', $column)) {
                    $hasAnyActiveColumn = true;
                    $query->orWhere($column, true);
                }
            }
        });

        $connections = $hasAnyActiveColumn ? $query->get() : collect();

        if ($connections->isNotEmpty()) {
            return $connections;
        }

        if (Schema::hasColumn('ldap_connections', 'schema_write_enabled')) {
            $connections = LdapConnection::query()
                ->where('schema_write_enabled', true)
                ->get();

            if ($connections->isNotEmpty()) {
                return $connections;
            }
        }

        if (Schema::hasColumn('ldap_connections', 'is_default')) {
            $connections = LdapConnection::query()
                ->where('is_default', true)
                ->get();

            if ($connections->isNotEmpty()) {
                return $connections;
            }
        }

        return LdapConnection::query()
            ->orderBy('id')
            ->limit(1)
            ->get();
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
