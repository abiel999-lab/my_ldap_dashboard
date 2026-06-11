<?php

namespace App\Console\Commands\Directory;

use App\Jobs\Directory\SyncDirectoryObjectsJob;
use App\Models\Directory\LdapConnection;
use App\Services\Directory\LdapUserSyncService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class ReconcileLdapCacheCommand extends Command
{
    protected $signature = 'iam:reconcile-ldap-cache
        {--connection= : LDAP connection ID}
        {--users=1 : Sync ldap_user_entries}
        {--objects=1 : Sync ldap_directory_entries}
        {--verify=1 : Run iam:verify-ldap-sync-state after sync}
        {--reason=manual : Reason/source for log}';

    protected $description = 'Reconcile PostgreSQL LDAP cache with real LDAP source of truth.';

    public function handle(LdapUserSyncService $userSyncService): int
    {
        $connectionId = $this->option('connection') !== null && $this->option('connection') !== ''
            ? (int) $this->option('connection')
            : null;

        $connections = LdapConnection::query()
            ->when($connectionId, fn ($query) => $query->whereKey($connectionId))
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        if ($connections->isEmpty()) {
            $this->error('No active LDAP connection found.');

            return self::FAILURE;
        }

        $runUsers = filter_var($this->option('users'), FILTER_VALIDATE_BOOL);
        $runObjects = filter_var($this->option('objects'), FILTER_VALIDATE_BOOL);
        $runVerify = filter_var($this->option('verify'), FILTER_VALIDATE_BOOL);
        $reason = (string) $this->option('reason');

        foreach ($connections as $connection) {
            $this->info("=== Reconciling {$connection->name} [ID {$connection->id}] ===");

            if ($runUsers) {
                $this->line('1. Sync users...');
                $result = $userSyncService->sync($connection);

                if (! ($result['ok'] ?? false)) {
                    $this->error('User sync failed: '.($result['message'] ?? 'unknown'));

                    return self::FAILURE;
                }

                $this->line('   seen='.($result['seen'] ?? 0)
                    .' created='.($result['created'] ?? 0)
                    .' updated='.($result['updated'] ?? 0)
                    .' missing='.($result['missing'] ?? 'n/a'));
            }

            if ($runObjects) {
                $this->line('2. Sync directory objects...');

                $execution = SafeCommandExecutionLogger::createQueued(
                    'ldap_cache_reconcile_directory_objects',
                    'direct job: SyncDirectoryObjectsJob from iam:reconcile-ldap-cache',
                    [
                        'operation' => 'reconcile_ldap_directory_objects',
                        'ldap_connection_id' => $connection->id,
                        'ldap_connection_name' => $connection->name,
                        'reason' => $reason,
                        'queue' => 'sync',
                    ]
                );

                try {
                    SyncDirectoryObjectsJob::dispatchSync(
                        (int) $connection->id,
                        SafeCommandExecutionLogger::id($execution)
                    );
                } catch (Throwable $e) {
                    SafeCommandExecutionLogger::markFailed(
                        SafeCommandExecutionLogger::id($execution),
                        $e->getMessage(),
                        [
                            'operation' => 'reconcile_ldap_directory_objects',
                            'ldap_connection_id' => $connection->id,
                            'reason' => $reason,
                        ]
                    );

                    throw $e;
                }

                $this->line('   directory object sync dispatched sync.');
            }
        }

        if ($runVerify) {
            $this->newLine();
            $this->info('3. Verify LDAP sync state...');

            foreach ($connections as $connection) {
                $verifyExecution = SafeCommandExecutionLogger::createQueued(
                    'ldap_sync_state_verify',
                    'iam:verify-ldap-sync-state after iam:reconcile-ldap-cache',
                    [
                        'operation' => 'ldap_sync_state_verify',
                        'source' => 'iam:reconcile-ldap-cache',
                        'ldap_connection_id' => $connection->id,
                        'ldap_connection_name' => $connection->name,
                        'reason' => $reason,
                        'destructive' => false,
                    ]
                );

                try {
                    $exitCode = Artisan::call('iam:verify-ldap-sync-state', [
                        '--connection' => $connection->id,
                        '--bind-dn' => 'cn=admin,' . ($connection->base_dn ?? 'dc=petra,dc=ac,dc=id'),
                        '--bind-password' => env('LDAP_VERIFY_BIND_PASSWORD') ?: env('LDAP_ADMIN_PASSWORD') ?: 'SeongJinWoo999!',
                        '--json' => true,
                    ]);

                    $output = trim(Artisan::output());
                    $decoded = json_decode($output, true);
                    $finalStatus = (string) ($decoded['final_status'] ?? 'unknown');

                    if ($exitCode === self::SUCCESS) {
                        SafeCommandExecutionLogger::markSuccess(
                            SafeCommandExecutionLogger::id($verifyExecution),
                            $decoded ?: $output,
                            [
                                'operation' => 'ldap_sync_state_verify',
                                'source' => 'iam:reconcile-ldap-cache',
                                'ldap_connection_id' => $connection->id,
                                'ldap_connection_name' => $connection->name,
                                'final_status' => $finalStatus,
                                'status_semantics' => $finalStatus === 'success' ? 'success' : 'success_with_warnings',
                            ]
                        );

                        $this->line('   verify final_status=' . $finalStatus);
                    } else {
                        SafeCommandExecutionLogger::markFailed(
                            SafeCommandExecutionLogger::id($verifyExecution),
                            'LDAP sync state verification failed.',
                            $decoded ?: $output,
                            [
                                'operation' => 'ldap_sync_state_verify',
                                'source' => 'iam:reconcile-ldap-cache',
                                'ldap_connection_id' => $connection->id,
                                'ldap_connection_name' => $connection->name,
                                'final_status' => $finalStatus,
                            ]
                        );

                        $this->error('   verify failed for connection ID ' . $connection->id);

                        return self::FAILURE;
                    }
                } catch (Throwable $e) {
                    SafeCommandExecutionLogger::markFailed(
                        SafeCommandExecutionLogger::id($verifyExecution),
                        $e->getMessage(),
                        [
                            'exception' => $e::class,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ],
                        [
                            'operation' => 'ldap_sync_state_verify',
                            'source' => 'iam:reconcile-ldap-cache',
                            'ldap_connection_id' => $connection->id,
                            'ldap_connection_name' => $connection->name,
                        ]
                    );

                    throw $e;
                }
            }
        }

        $this->info('LDAP cache reconciliation completed.');

        return self::SUCCESS;
    }
}
