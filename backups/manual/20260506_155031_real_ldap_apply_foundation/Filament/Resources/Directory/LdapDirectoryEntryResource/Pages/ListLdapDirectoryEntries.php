<?php

namespace App\Filament\Resources\Directory\LdapDirectoryEntryResource\Pages;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource;
use App\Jobs\Ldap\RefreshLdapDirectoryCacheJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Ldap\LdapDirectoryBrowserService;
use App\Services\Operations\OperationJobTracker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapDirectoryEntries extends ListRecords
{
    protected static string $resource = LdapDirectoryEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearBrowserCache')
                ->label('Clear Browser Cache')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear LDAP browser cache?')
                ->modalDescription('This only deletes cached LDAP directory entries from the dashboard database. It does not delete or modify real LDAP data.')
                ->action(function (): void {
                    $deleted = LdapDirectoryEntry::query()->delete();

                    app(AuditLogger::class)->log([
                        'module' => 'directory.ldap_browser',
                        'action' => 'clear_cache',
                        'status' => 'success',
                        'target_type' => LdapDirectoryEntry::class,
                        'target_key' => 'all',
                        'request_payload' => [
                            'deleted_cache_entries' => $deleted,
                            'ldap_data_modified' => false,
                        ],
                    ]);

                    Notification::make()
                        ->title('LDAP browser cache cleared')
                        ->body($deleted.' cached entries were deleted. Real LDAP data was not modified.')
                        ->success()
                        ->send();
                }),

            Action::make('refreshDefaultLdapCache')
                ->label('Refresh Default LDAP Cache')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Refresh LDAP directory cache?')
                ->modalDescription('This will run a safe read-only LDAP search on the default LDAP connection and cache up to 200 entries.')
                ->action(function (): void {
                    $connection = LdapConnection::query()
                        ->where('is_default', true)
                        ->where('is_active', true)
                        ->first();

                    if (! $connection) {
                        Notification::make()
                            ->title('No active default LDAP connection')
                            ->body('Set one LDAP connection as active and default first.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = app(OperationJobTracker::class)->create([
                        'type' => 'ldap_directory_cache_refresh',
                        'name' => 'Refresh LDAP Directory Cache - '.$connection->name,
                        'module' => 'directory.ldap_browser',
                        'action' => 'refresh_cache',
                        'status' => 'queued',
                        'source' => 'filament',
                        'target_type' => $connection::class,
                        'target_key' => (string) $connection->getKey(),
                        'target_dn' => $connection->base_dn,
                        'ldap_connection_id' => $connection->id,
                        'total_items' => 1,
                        'processed_items' => 0,
                        'success_items' => 0,
                        'failed_items' => 0,
                        'metadata' => [
                            'source' => 'filament',
                            'action' => 'refresh_cache',
                            'target_type' => $connection::class,
                            'target_key' => (string) $connection->getKey(),
                            'target_dn' => $connection->base_dn,
                            'ldap_connection_id' => $connection->id,
                            'connection_name' => $connection->name,
                            'base_dn' => $connection->base_dn,
                            'filter' => '(objectClass=*)',
                            'limit' => 200,
                            'queue' => 'ldap',
                        ],
                    ]);

                    RefreshLdapDirectoryCacheJob::dispatch(
                        operationJobId: $operationJob->id,
                        ldapConnectionId: $connection->id,
                        baseDn: $connection->base_dn,
                        filter: '(objectClass=*)',
                        limit: 200,
                    );

                    app(AuditLogger::class)->log([
                        'module' => 'directory.ldap_browser',
                        'action' => 'queue_refresh_cache',
                        'status' => 'success',
                        'target_type' => $connection::class,
                        'target_key' => (string) $connection->getKey(),
                        'ldap_connection_id' => $connection->id,
                        'target_dn' => $connection->base_dn,
                        'operation_job_id' => $operationJob->id,
                        'request_payload' => [
                            'base_dn' => $connection->base_dn,
                            'filter' => '(objectClass=*)',
                            'limit' => 200,
                            'queue' => 'ldap',
                        ],
                    ]);

                    Notification::make()
                        ->title('LDAP cache refresh queued')
                        ->body('Operation Job #'.$operationJob->id.' was created and queued. Check Operation Jobs for progress.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
