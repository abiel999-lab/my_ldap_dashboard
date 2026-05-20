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

            Actions\Action::make('apply')
                ->label('Apply')
                ->visible(fn (): bool => ! empty($this->record->preview_result))
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Apply LDAP Bulk Operation?')
                ->modalDescription('Apply akan menjalankan operasi berdasarkan preview yang sudah dibuat.')
                ->action(function (): void {
                    $result = app(LdapCrudOperationService::class)->apply($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Apply finished' : 'Apply failed')
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
                ->visible(fn (): bool => ! empty($this->record->rollback_payload))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rollback LDAP Bulk Operation?')
                ->modalDescription('Rollback akan memakai rollback payload dari apply terakhir.')
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
