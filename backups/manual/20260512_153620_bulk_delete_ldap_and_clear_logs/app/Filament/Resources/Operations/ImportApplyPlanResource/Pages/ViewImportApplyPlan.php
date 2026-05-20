<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Operations\FastImportApplyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

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
                    try {
                        /** @var ImportApplyPlan $record */
                        $record = $this->record->fresh();

                        if (! $record) {
                            Notification::make()
                                ->title('Real apply failed')
                                ->body('Import apply plan record was not found.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(FastImportApplyService::class)->apply($record);

                        $record->refresh();

                        if (! ($result['ok'] ?? false)) {
                            Notification::make()
                                ->title('Real apply failed')
                                ->body($result['message'] ?? 'Real apply failed. Check Command Executions.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Import applied')
                            ->body($result['message'] ?? 'Import applied successfully.')
                            ->success()
                            ->send();

                        return;
                    } catch (Throwable $exception) {
                        try {
                            /** @var ImportApplyPlan|null $record */
                            $record = $this->record ?? null;

                            if ($record) {
                                $record->forceFill([
                                    'status' => 'failed',
                                    'message' => $exception->getMessage(),
                                    'real_apply_error_message' => $exception->getMessage(),
                                    'apply_blocked_reason' => null,
                                    'finished_at' => now(),
                                ])->save();
                            }
                        } catch (Throwable $ignored) {
                            //
                        }

                        report($exception);

                        Notification::make()
                            ->title('Real apply crashed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }
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
        } catch (Throwable $exception) {
            return false;
        }
    }
}
