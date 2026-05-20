<?php

namespace App\Filament\Resources\Directory\LdapRoleEntryResource\Pages;

use App\Filament\Resources\Directory\LdapRoleEntryResource;
use App\Services\Directory\LdapRoleSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapRoleEntries extends ListRecords
{
    protected static string $resource = LdapRoleEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncRoles')
                ->label('Sync LDAP Roles')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP roles?')
                ->modalDescription('This reads cached LDAP groups and updates the local role index. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapRoleSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP role sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP role sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
