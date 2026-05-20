<?php

namespace App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;

use App\Filament\Resources\Operations\BulkLdapOperationResource;
use App\Models\Operations\BulkLdapOperationItem;
use App\Services\Operations\BulkLdapUserOperationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;

class ViewBulkLdapOperation extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = BulkLdapOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Generate Preview')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Generate bulk LDAP preview?')
                ->modalDescription('This generates operation items only. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(BulkLdapUserOperationService::class)->previewCreateTestUsers($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Preview generated' : 'Preview failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->record->refresh();
                }),

            Action::make('queueApply')
                ->label('Queue Apply')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, ['previewed', 'partial_success', 'failed'], true))
                ->requiresConfirmation()
                ->modalHeading('Queue bulk LDAP create users?')
                ->modalDescription('This will create missing users through Laravel Queue. Existing users will be marked already_applied.')
                ->modalSubmitActionLabel('Queue Bulk Create')
                ->action(function (): void {
                    $result = app(BulkLdapUserOperationService::class)->queueApply($this->record, false);

                    Notification::make()
                        ->title($result['ok'] ? 'Bulk operation queued' : 'Queue failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->record->refresh();
                }),

            Action::make('retryFailed')
                ->label('Retry Failed')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record->failed_items > 0 || in_array($this->record->status, ['partial_success', 'failed'], true))
                ->requiresConfirmation()
                ->modalHeading('Retry failed/pending items?')
                ->modalDescription('Success and already_applied items will not be executed again.')
                ->modalSubmitActionLabel('Retry Failed Only')
                ->action(function (): void {
                    $result = app(BulkLdapUserOperationService::class)->queueApply($this->record, true);

                    Notification::make()
                        ->title($result['ok'] ? 'Retry queued' : 'Retry failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->send();

                    $this->record->refresh();
                }),

            EditAction::make(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                BulkLdapOperationItem::query()
                    ->where('bulk_ldap_operation_id', $this->record->id)
            )
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('sequence')->label('#')->sortable(),
                TextColumn::make('uid')->label('UID')->searchable()->sortable(),
                TextColumn::make('action')->label('Action')->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'gray',
                        'running' => 'warning',
                        'success', 'already_applied' => 'success',
                        'failed' => 'danger',
                        'conflict' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('target_dn')->label('Target DN')->limit(60)->searchable(),
                TextColumn::make('attempt_count')->label('Try')->sortable(),
                TextColumn::make('exit_code')->label('Exit')->placeholder('N/A')->sortable(),
                TextColumn::make('error_message')->label('Error')->limit(80)->placeholder('N/A'),
                TextColumn::make('finished_at')->label('Finished')->dateTime()->placeholder('N/A')->sortable(),
            ])
            ->defaultPaginationPageOption(10);
    }
}
