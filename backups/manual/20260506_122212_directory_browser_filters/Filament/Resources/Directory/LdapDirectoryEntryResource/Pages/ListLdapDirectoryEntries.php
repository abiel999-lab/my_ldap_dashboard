<?php

namespace App\Filament\Resources\Directory\LdapDirectoryEntryResource\Pages;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource;
use App\Models\Directory\LdapConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Ldap\LdapDirectoryBrowserService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapDirectoryEntries extends ListRecords
{
    protected static string $resource = LdapDirectoryEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

                    $result = app(LdapDirectoryBrowserService::class)->refreshCache(
                        connection: $connection,
                        baseDn: $connection->base_dn,
                        filter: '(objectClass=*)',
                        limit: 200,
                    );

                    app(AuditLogger::class)->log([
                        'module' => 'directory.ldap_browser',
                        'action' => 'refresh_cache',
                        'status' => $result['ok'] ? 'success' : 'failed',
                        'target_type' => $connection::class,
                        'target_key' => (string) $connection->getKey(),
                        'ldap_connection_id' => $connection->id,
                        'target_dn' => $connection->base_dn,
                        'request_payload' => [
                            'base_dn' => $connection->base_dn,
                            'filter' => '(objectClass=*)',
                            'limit' => 200,
                        ],
                        'error_message' => $result['ok'] ? null : $result['message'],
                        'duration_ms' => $result['duration_ms'],
                    ]);

                    Notification::make()
                        ->title($result['ok'] ? 'LDAP cache refreshed' : 'LDAP cache refresh failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
