<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Filament\Resources\Operations\ImportBatchResource;
use App\Models\Operations\ImportBatch;
use App\Services\Operations\ImportApplyPlanService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateApplyPlan')
                ->label('Generate Apply Plan')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->visible(function (): bool {
                    /** @var ImportBatch $record */
                    $record = $this->record;

                    return $this->canGenerateApplyPlan($record);
                })
                ->requiresConfirmation()
                ->modalHeading('Generate import apply plan?')
                ->modalDescription(function (): string {
                    /** @var ImportBatch $record */
                    $record = $this->record;

                    $deleteRows = $record->rows()
                        ->where('status', 'valid')
                        ->where('action_plan', 'delete')
                        ->count();

                    if ($deleteRows > 0) {
                        return 'This import contains DELETE rows. This will generate an LDIF delete apply plan. LDAP data will not be changed yet until Real Apply.';
                    }

                    return 'This will generate an LDIF apply plan from valid preview rows. LDAP data will not be changed yet.';
                })
                ->modalSubmitActionLabel('Generate Plan')
                ->action(function () {
                    /** @var ImportBatch $record */
                    $record = $this->record;

                    $result = app(ImportApplyPlanService::class)->generate($record);

                    if (! ($result['ok'] ?? false)) {
                        Notification::make()
                            ->title('Generate apply plan failed')
                            ->body($result['message'] ?? 'Unable to generate apply plan.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    Notification::make()
                        ->title('Apply plan generated')
                        ->body($result['message'] ?? 'Import apply plan generated successfully.')
                        ->success()
                        ->send();

                    $planId = $result['plan_id'] ?? null;

                    if ($planId) {
                        return redirect()->to(
                            ImportApplyPlanResource::getUrl('view', ['record' => $planId])
                        );
                    }

                    return redirect()->to(
                        ImportApplyPlanResource::getUrl('index')
                    );
                }),

            Action::make('openApplyPlans')
                ->label('Open Apply Plans')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (): string => ImportApplyPlanResource::getUrl('index')),

            EditAction::make()
                ->label('Edit'),
        ];
    }

    private function canGenerateApplyPlan(ImportBatch $record): bool
    {
        if (! in_array($record->status, [
            'previewed',
            'previewed_with_errors',
            'preview_completed',
            'preview_completed_with_issues',
        ], true)) {
            return false;
        }

        if (
            (int) $record->will_create_rows > 0
            || (int) $record->will_update_rows > 0
        ) {
            return true;
        }

        return $record->rows()
            ->where('status', 'valid')
            ->whereIn('action_plan', ['create', 'update', 'modify', 'delete'])
            ->exists();
    }
}
