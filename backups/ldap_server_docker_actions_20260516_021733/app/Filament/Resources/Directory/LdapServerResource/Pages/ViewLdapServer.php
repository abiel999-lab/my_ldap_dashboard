<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Models\Directory\LdapServer;
use App\Services\Directory\LdapServerProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapServer extends ViewRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('refreshArtifacts')
                ->label('Refresh Artifacts')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);

                    Notification::make()
                        ->title('Artifacts refreshed')
                        ->success()
                        ->send();
                }),

            Action::make('testConnection')
                ->label('Test LDAP Bind')
                ->icon('heroicon-o-signal')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var LdapServer $record */
                    $record = $this->record;

                    $result = app(LdapServerProvisioningService::class)->testConnection($record);

                    $record->forceFill([
                        'last_tested_at' => now(),
                        'last_test_status' => $result['ok'] ? 'success' : 'failed',
                        'status' => $result['ok'] ? 'online' : 'error',
                        'last_error' => $result['ok'] ? null : $result['message'],
                    ])->save();

                    Notification::make()
                        ->title($result['ok'] ? 'LDAP bind success' : 'LDAP bind failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),

            Action::make('registerConnection')
                ->label('Register to LDAP Connections')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->action(function (): void {
                    $result = app(LdapServerProvisioningService::class)->registerAsLdapConnection($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'LDAP Connection registered' : 'Register failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
