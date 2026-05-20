<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use Filament\Forms\Components\Textarea;
use App\Services\Ldap\LdapEntryAttributeFormatterService;

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
            
            Action::make('attributeStudio')
                ->label('Attribute Studio')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('info')
                ->modalHeading('LDAP User Attribute Studio')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->form([
                    Textarea::make('attributes_preview')
                        ->label('Formatted LDAP Attributes')
                        ->default(fn () => app(LdapEntryAttributeFormatterService::class)->formatForTextarea($this->record))
                        ->disabled()
                        ->rows(28)
                        ->columnSpanFull(),
                ]),
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
