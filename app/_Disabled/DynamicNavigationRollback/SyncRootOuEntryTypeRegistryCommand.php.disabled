<?php

namespace App\Console\Commands\Directory;

use App\Support\Directory\RootOuEntryTypeRegistrySyncService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Console\Command;

class SyncRootOuEntryTypeRegistryCommand extends Command
{
    protected $signature = 'iam:sync-root-ou-entry-types
        {--connection= : LDAP connection ID}
        {--root-dn= : Root DN, example dc=petra,dc=ac,dc=id}';

    protected $description = 'Discover LDAP organizationalUnit entries from root DN and auto-register them as dynamic Entry Type Registry navigation items.';

    public function handle(RootOuEntryTypeRegistrySyncService $service): int
    {
        $connectionId = $this->option('connection') ? (int) $this->option('connection') : null;
        $rootDn = $this->option('root-dn') ? (string) $this->option('root-dn') : null;

        $execution = SafeCommandExecutionLogger::createQueued(
            'sync_root_ou_entry_type_registry',
            'php artisan iam:sync-root-ou-entry-types',
            [
                'operation' => 'sync_root_ou_entry_type_registry',
                'connection_id' => $connectionId,
                'root_dn' => $rootDn,
            ]
        );

        $result = $service->sync(
            connectionId: $connectionId,
            rootDn: $rootDn,
            commandExecutionId: SafeCommandExecutionLogger::id($execution),
        );

        if ($result['ok'] ?? false) {
            SafeCommandExecutionLogger::markSuccess(
                SafeCommandExecutionLogger::id($execution),
                $result,
                $result
            );

            $this->info('Root OU Entry Type Registry sync completed.');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        SafeCommandExecutionLogger::markFailed(
            SafeCommandExecutionLogger::id($execution),
            implode('; ', $result['errors'] ?? ['Unknown error']),
            $result,
            $result
        );

        $this->error('Root OU Entry Type Registry sync failed.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
