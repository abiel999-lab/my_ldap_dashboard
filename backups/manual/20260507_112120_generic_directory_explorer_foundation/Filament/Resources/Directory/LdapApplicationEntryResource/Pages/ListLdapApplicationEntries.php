<?php

namespace App\Filament\Resources\Directory\LdapApplicationEntryResource\Pages;

use App\Filament\Resources\Directory\LdapApplicationEntryResource;
use App\Services\Directory\LdapApplicationSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapApplicationEntries extends ListRecords
{
    protected static string $resource = LdapApplicationEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncApplications')
                ->label('Sync LDAP Applications')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP applications?')
                ->modalDescription('This reads cached LDAP groups and updates the local application registry. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapApplicationSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP application sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP application sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
