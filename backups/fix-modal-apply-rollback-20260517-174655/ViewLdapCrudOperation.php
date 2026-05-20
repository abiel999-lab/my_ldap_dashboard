<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use App\Services\Operations\LdapCrudOperationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapCrudOperation extends ViewRecord
{
    protected static string $resource = LdapCrudOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Generate Preview')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->preview($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Preview ready' : 'Preview failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'preview_result',
                        'previewed_at',
                    ]);
                }),

            Actions\Action::make('dryRun')
                ->label('Run Dry-run')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Run dry-run?')
                ->modalDescription('Dry-run tidak mengubah LDAP asli. Sistem hanya membuat result, log, audit log, dan rollback payload.')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->runDryRun($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Dry-run finished' : 'Dry-run failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'execution_result',
                        'rollback_payload',
                        'executed_at',
                    ]);
                }),

            Actions\Action::make('rollback')
                ->label('Rollback')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rollback LDAP Bulk Operation?')
                ->modalDescription('Batch ini masih rollback safe mode. LDAP asli belum diubah.')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->rollback($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Rollback finished' : 'Rollback failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'rollback_result',
                        'rolled_back_at',
                    ]);
                }),

            Actions\EditAction::make(),
        ];
    }
}
