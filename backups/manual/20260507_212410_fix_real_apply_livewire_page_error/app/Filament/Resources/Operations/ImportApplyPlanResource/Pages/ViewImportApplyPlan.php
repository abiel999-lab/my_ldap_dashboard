<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
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
                    ? 'Real apply delete import?'
                    : 'Real apply import?')
                ->modalDescription(fn (): string => $this->planContainsDelete($this->record)
                    ? 'This will delete LDAP entries from the generated LDIF plan. Logs will be recorded.'
                    : 'This will apply the generated LDIF plan to LDAP. Logs will be recorded.')
                ->modalSubmitActionLabel('Real Apply')
                ->action(function (): void {
                    /** @var ImportApplyPlan $record */
                    $record = $this->record;

                    $result = app(FastImportApplyService::class)->apply($record);

                    if (! ($result['ok'] ?? false)) {
                        Notification::make()
                            ->title('Real apply failed')
                            ->body($result['message'] ?? 'Real apply failed. Check Command Executions.')
                            ->danger()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record->id]));

                        return;
                    }

                    Notification::make()
                        ->title('Import applied')
                        ->body($result['message'] ?? 'Import applied successfully.')
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record->id]));
                }),

            Action::make('backToPlans')
                ->label('Back to Plans')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('index')),
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
            'success_applied',
        ], true)) {
            return false;
        }

        return filled($plan->output_path) && filled($plan->ldap_connection_id);
    }

    private function planContainsDelete(ImportApplyPlan $plan): bool
    {
        try {
            $content = app(FastImportApplyService::class)->readLdif($plan);

            return str_contains(strtolower($content), 'changetype: delete');
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
