<?php

namespace App\Console\Commands\Directory;

use App\Jobs\Directory\SyncLdapSchemaEntriesJob;
use App\Models\Directory\LdapConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DirectSyncLdapSchemaCommand extends Command
{
    protected $signature = 'iam:ldap-schema-sync-direct {connectionId?}';

    protected $description = 'Run LDAP schema sync directly without queue for debugging.';

    public function handle(): int
    {
        $connectionId = $this->argument('connectionId');

        $executionId = DB::table('command_executions')->insertGetId($this->executionRow());

        try {
            if ($connectionId) {
                $connection = LdapConnection::query()->findOrFail($connectionId);

                $this->info("Running direct schema sync for LDAP connection ID {$connection->id}: {$connection->name}");

                SyncLdapSchemaEntriesJob::dispatchSync($connection->id, $executionId);
            } else {
                $connections = LdapConnection::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->get();

                $this->info('Running direct schema sync for active LDAP connections: '.$connections->count());

                foreach ($connections as $connection) {
                    $this->line("Sync connection ID {$connection->id}: {$connection->name}");
                    SyncLdapSchemaEntriesJob::dispatchSync($connection->id, $executionId);
                }
            }

            DB::table('command_executions')
                ->where('id', $executionId)
                ->update([
                    'status' => 'success',
                    'exit_code' => 0,
                    'stdout' => 'Direct schema sync finished.',
                    'stderr' => null,
                    'error_message' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->info("Direct schema sync success. Command Execution ID: {$executionId}");

            $this->table(
                ['ldap_connection_id', 'schema_type', 'total'],
                DB::table('ldap_schema_entries')
                    ->select('ldap_connection_id', 'schema_type', DB::raw('count(*) as total'))
                    ->groupBy('ldap_connection_id', 'schema_type')
                    ->orderBy('ldap_connection_id')
                    ->orderBy('schema_type')
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->toArray()
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::table('command_executions')
                ->where('id', $executionId)
                ->update([
                    'status' => 'failed',
                    'exit_code' => 1,
                    'stderr' => $e->getMessage(),
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function executionRow(): array
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'command_type' => 'ldap_schema_sync_direct',
            'command' => 'php artisan iam:ldap-schema-sync-direct',
            'status' => 'running',
            'is_safe_mode' => true,
            'safe_mode' => true,
            'is_preview' => false,
            'preview_mode' => false,
            'destructive' => false,
            'module' => 'directory.schema_browser',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('command_executions');

        return collect($row)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->toArray();
    }
}
