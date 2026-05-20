<?php

namespace App\Filament\Resources\Directory\LdapGroupEntryResource\Pages;

use App\Filament\Resources\Directory\LdapGroupEntryResource;
use App\Services\Directory\LdapSingleGroupRefreshDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapGroupEntry extends ViewRecord
{
    protected static string $resource = LdapGroupEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshThisGroup')
                ->label('Refresh This Group')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Refresh this LDAP group?')
                ->modalDescription('This reads this group directly from LDAP and updates the local cache. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapSingleGroupRefreshDispatcher::class)->queue($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue group refresh')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP group refresh queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
