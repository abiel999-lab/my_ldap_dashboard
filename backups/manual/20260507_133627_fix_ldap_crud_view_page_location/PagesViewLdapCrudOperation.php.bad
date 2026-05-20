<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use App\Services\Operations\LdapCrudOperationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapCrudOperation extends ViewRecord
{
    protected static string $resource = LdapCrudOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Generate Preview')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Generate LDAP CRUD preview?')
                ->modalDescription('This only generates LDIF preview and validation. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->preview($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Preview generated' : 'Preview has validation errors')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->record->refresh();
                }),

            Action::make('dryRun')
                ->label('Run Dry-run')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Run LDAP CRUD dry-run?')
                ->modalDescription('This runs ldapmodify with -n. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->dryRun($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Dry-run success' : 'Dry-run failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->record->refresh();
                }),

            EditAction::make(),
        ];
    }
}
