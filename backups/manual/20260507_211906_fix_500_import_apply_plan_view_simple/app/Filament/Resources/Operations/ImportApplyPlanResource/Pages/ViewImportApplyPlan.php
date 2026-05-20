<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Filament\Resources\Operations\LdapUserEntryResource;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Operations\FastImportApplyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportApplyPlan extends ViewRecord
{
    protected static string $resource = ImportApplyPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('realApplyNow')
                ->label('Real Apply')
                ->icon('heroicon-o-play')
                ->color(fn (): string => $this->planContainsDelete($this->record) ? 'danger' : 'success')
                ->visible(fn (): bool => $this->canRealApply($this->record))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->planContainsDelete($this->record)
                    ? 'Real apply DELETE import?'
                    : 'Real apply import?')
                ->modalDescription(fn (): string => $this->planContainsDelete($this->record)
                    ? 'This apply plan contains LDAP delete operations. Entries will be deleted from LDAP. Command Execution and Audit Log will record this action.'
                    : 'This will apply the generated LDIF plan to LDAP. Command Execution and Audit Log will record this action.')
                ->modalSubmitActionLabel('Real Apply')
                ->action(function () {
                    /** @var ImportApplyPlan $record */
                    $record = $this->record;

                    $result = app(FastImportApplyService::class)->apply($record);

                    if (! ($result['ok'] ?? false)) {
                        Notification::make()
                            ->title('Real apply failed')
                            ->body($result['message'] ?? 'Real apply failed. Check Command Executions.')
                            ->danger()
                            ->send();

                        $this->refreshFormData([
                            'status',
                            'message',
                            'apply_blocked_reason',
                            'real_apply_error_message',
                        ]);

                        return null;
                    }

                    Notification::make()
                        ->title('Import applied')
                        ->body($result['message'] ?? 'Import applied successfully.')
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record->id]));
                }),

            Action::make('download')
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('download', ['record' => $this->record]))
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => method_exists(static::getResource(), 'getUrl') && filled($this->record->output_path)),
        ];
    }

    private function canRealApply(ImportApplyPlan $plan): bool
    {
        $status = strtolower((string) $plan->status);

        if (in_array($status, [
            'applied',
            'applied_verified',
            'applied_and_verified',
            'applied & verified',
        ], true)) {
            return false;
        }

        return filled($plan->output_path)
            && (bool) ($plan->ldap_connection_id);
    }

    private function planContainsDelete(ImportApplyPlan $plan): bool
    {
        $content = app(FastImportApplyService::class)->readLdif($plan);

        return str_contains(strtolower($content), 'changetype: delete');
    }
}
