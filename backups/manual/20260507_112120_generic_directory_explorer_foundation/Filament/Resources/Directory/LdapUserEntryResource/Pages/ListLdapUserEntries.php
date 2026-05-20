<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapUserSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapUserEntries extends ListRecords
{
    protected static string $resource = LdapUserEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncUsers')
                ->label('Sync LDAP Users')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP users?')
                ->modalDescription('This reads LDAP users and updates the local user index. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapUserSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP user sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP user sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
