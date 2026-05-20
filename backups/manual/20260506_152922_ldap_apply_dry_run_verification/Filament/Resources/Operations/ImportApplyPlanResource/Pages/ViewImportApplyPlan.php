<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\ImportApplySafetyGateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Response;

class ViewImportApplyPlan extends ViewRecord
{
    protected static string $resource = ImportApplyPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestApproval')
                ->label('Request Approval')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->visible(fn (): bool => $this->record->canRequestApproval())
                ->schema([
                    Textarea::make('approval_note')
                        ->label('Approval Request Note')
                        ->rows(4)
                        ->default('Please review this generated LDIF apply plan. LDAP data has not been changed.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(ImportApplySafetyGateService::class)->requestApproval(
                        plan: $this->record,
                        note: $data['approval_note'] ?? null,
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Approval request failed')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Approval requested')
                        ->body($result['message'])
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'approval_status',
                        'approval_note',
                        'apply_blocked_reason',
                        'updated_at',
                    ]);
                }),

            Action::make('approvePlan')
                ->label('Approve Plan')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->canApprove())
                ->requiresConfirmation()
                ->modalHeading('Approve apply plan?')
                ->modalDescription('This only approves the generated LDIF plan for a future apply step. It will not change LDAP data.')
                ->schema([
                    Textarea::make('approval_note')
                        ->label('Approval Note')
                        ->rows(4)
                        ->default('I reviewed the generated LDIF apply plan and approve it for the future LDAP apply step. LDAP data is not changed by this approval.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(ImportApplySafetyGateService::class)->approve(
                        plan: $this->record,
                        note: $data['approval_note'] ?? '',
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Approval failed')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Plan approved')
                        ->body($result['message'])
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'approval_status',
                        'approval_note',
                        'approved_by',
                        'approved_at',
                        'message',
                        'updated_at',
                    ]);
                }),

            Action::make('rejectPlan')
                ->label('Reject Plan')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->canReject())
                ->requiresConfirmation()
                ->modalHeading('Reject apply plan?')
                ->modalDescription('This rejects the apply plan. LDAP data will not be changed.')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection Reason')
                        ->rows(4)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(ImportApplySafetyGateService::class)->reject(
                        plan: $this->record,
                        reason: $data['rejection_reason'] ?? '',
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Rejection failed')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Plan rejected')
                        ->body($result['message'])
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'approval_status',
                        'rejected_by',
                        'rejected_at',
                        'rejection_reason',
                        'message',
                        'updated_at',
                    ]);
                }),

            Action::make('downloadLdifPlan')
                ->label('Download LDIF Plan')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (): bool => $this->record->hasOutputFile())
                ->action(function () {
                    /** @var ImportApplyPlan $record */
                    $record = $this->record;

                    if (! $record->hasOutputFile()) {
                        Notification::make()
                            ->title('Apply plan file missing')
                            ->body('The generated LDIF plan file does not exist.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    app(AuditLogger::class)->log([
                        'module' => 'operations.import',
                        'action' => 'download_import_apply_ldif_plan',
                        'status' => 'success',
                        'target_type' => ImportApplyPlan::class,
                        'target_key' => (string) $record->id,
                        'operation_job_id' => $record->operation_job_id,
                        'request_payload' => [
                            'output_path' => $record->output_path,
                            'output_size_bytes' => $record->output_size_bytes,
                            'output_hash' => $record->output_hash,
                        ],
                    ]);

                    return Response::download(
                        $record->outputAbsolutePath(),
                        $record->outputFilename(),
                        [
                            'Content-Type' => 'text/plain',
                        ],
                    );
                }),
        ];
    }
}
