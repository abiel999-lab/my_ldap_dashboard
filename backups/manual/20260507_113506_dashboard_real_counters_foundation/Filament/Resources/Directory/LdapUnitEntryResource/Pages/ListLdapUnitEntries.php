<?php

namespace App\Filament\Resources\Directory\LdapUnitEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUnitEntryResource;
use App\Services\Directory\LdapUnitSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapUnitEntries extends ListRecords
{
    protected static string $resource = LdapUnitEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncUnits')
                ->label('Sync LDAP Units')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP units / OUs?')
                ->modalDescription('This reads cached LDAP organizational units and updates the local unit index. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapUnitSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP unit sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP unit sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
