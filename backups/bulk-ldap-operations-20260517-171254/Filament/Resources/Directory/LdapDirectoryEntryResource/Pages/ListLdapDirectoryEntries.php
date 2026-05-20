<?php

namespace App\Filament\Resources\Directory\LdapDirectoryEntryResource\Pages;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource;
use App\Services\Directory\LdapDirectoryExplorerSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapDirectoryEntries extends ListRecords
{
    protected static string $resource = LdapDirectoryEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncDirectoryExplorer')
                ->label('Sync Directory Explorer')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP directory explorer?')
                ->modalDescription('This reads LDAP entries and updates the local directory explorer cache. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapDirectoryExplorerSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue directory explorer sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('Directory explorer sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
