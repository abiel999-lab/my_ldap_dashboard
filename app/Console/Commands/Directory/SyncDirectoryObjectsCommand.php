<?php

namespace App\Console\Commands\Directory;

use App\Jobs\Directory\SyncDirectoryObjectsJob;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Console\Command;

class SyncDirectoryObjectsCommand extends Command
{
    protected $signature = 'iam:sync-directory-objects
        {--connection= : LDAP connection ID}
        {--queue : Dispatch to queue instead of running inline}';

    protected $description = 'Sync LDAP directory objects from active LDAP connections into Directory Object Manager index.';

    public function handle(): int
    {
        $connectionId = $this->option('connection') ? (int) $this->option('connection') : null;

        $execution = SafeCommandExecutionLogger::createQueued(
            'ldap_directory_objects_sync_queued',
            'php artisan iam:sync-directory-objects',
            [
                'operation' => 'sync_directory_objects',
                'ldap_connection_id' => $connectionId,
                'queue' => 'ldap',
            ]
        );

        if ($this->option('queue')) {
            SyncDirectoryObjectsJob::dispatch($connectionId, SafeCommandExecutionLogger::id($execution));

            $this->info('Directory object sync queued. Command Execution ID: '.SafeCommandExecutionLogger::id($execution));

            return self::SUCCESS;
        }

        SyncDirectoryObjectsJob::dispatchSync($connectionId, SafeCommandExecutionLogger::id($execution));

        $this->info('Directory object sync completed. Command Execution ID: '.SafeCommandExecutionLogger::id($execution));

        return self::SUCCESS;
    }
}
