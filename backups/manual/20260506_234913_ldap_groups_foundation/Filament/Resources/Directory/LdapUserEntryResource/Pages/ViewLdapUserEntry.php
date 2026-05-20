<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapSingleUserRefreshDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapUserEntry extends ViewRecord
{
    protected static string $resource = LdapUserEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshThisUser')
                ->label('Refresh This User')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Refresh this LDAP user?')
                ->modalDescription('This reads this user directly from LDAP and updates the local cache. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapSingleUserRefreshDispatcher::class)->queue($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue user refresh')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP user refresh queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
