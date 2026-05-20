<?php

namespace App\Filament\Resources\Directory\LdapGroupEntryResource\Pages;

use App\Filament\Resources\Directory\LdapGroupEntryResource;
use App\Services\Directory\LdapGroupSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapGroupEntries extends ListRecords
{
    protected static string $resource = LdapGroupEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncGroups')
                ->label('Sync LDAP Groups')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP groups?')
                ->modalDescription('This reads LDAP groups and updates the local group index. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapGroupSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP group sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP group sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
