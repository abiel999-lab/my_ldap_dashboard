<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\ImportApplyDryRunDispatcher;
use App\Services\Operations\ImportApplyPlanRecoveryService;
use App\Services\Operations\ImportApplySafetyGateService;
use App\Services\Operations\ImportPostApplyVerificationDispatcher;
use App\Services\Operations\ImportRealApplyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

            Action::make('verifyDryRun')
                ->label('Verify Dry Run')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->visible(fn (): bool => $this->record->canVerifyDryRun())
                ->requiresConfirmation()
                ->modalHeading('Verify LDAP apply with ldapadd -n?')
                ->modalDescription('This runs ldapadd in dry-run/no-op mode. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(ImportApplyDryRunDispatcher::class)->queueVerify($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Dry-run verification failed to queue')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('Dry-run verification queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),

            Action::make('verifyAppliedEntries')
                ->label('Verify Applied')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->canVerifyPostApply())
                ->requiresConfirmation()
                ->modalHeading('Verify applied LDAP entries?')
                ->modalDescription('This runs ldapsearch for each DN in the applied LDIF plan. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(ImportPostApplyVerificationDispatcher::class)->queueVerify($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Post-apply verification failed to queue')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('Post-apply verification queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),

            Action::make('realApplyLdap')
                ->label('REAL Apply')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->canRealApply())
                ->requiresConfirmation()
                ->modalHeading('REAL LDAP APPLY - Are you sure?')
                ->modalDescription('This will run ldapadd WITHOUT dry-run mode. LDAP data WILL change. Type APPLY LDAP exactly to continue.')
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Type APPLY LDAP to confirm')
                        ->required()
                        ->helperText('This is case-sensitive. LDAP data will be changed if the apply succeeds.'),
                ])
                ->action(function (array $data): void {
                    $result = app(ImportRealApplyDispatcher::class)->queueRealApply(
                        plan: $this->record,
                        confirmation: $data['confirmation'] ?? '',
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Real LDAP apply failed to queue')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('REAL LDAP apply queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data may change when the job runs.')
                        ->warning()
                        ->send();
                }),

            Action::make('downloadLdifPlan')
                ->label('Download')
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

            ActionGroup::make([
                Action::make('openImportBatch')
                    ->label('Open Import Batch')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn () => $this->record->importBatchUrl())
                    ->openUrlInNewTab(),

                Action::make('openOperationJob')
                    ->label('Open Operation Job')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (): bool => filled($this->record->operation_job_id))
                    ->url(fn () => $this->record->operationJobUrl())
                    ->openUrlInNewTab(),

                Action::make('openDryRunCommand')
                    ->label('Open Dry Run Command')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (): bool => filled($this->record->dry_run_command_execution_id))
                    ->url(fn () => $this->record->dryRunCommandExecutionUrl())
                    ->openUrlInNewTab(),

                Action::make('openRealApplyCommand')
                    ->label('Open Real Apply Command')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (): bool => filled($this->record->real_apply_command_execution_id))
                    ->url(fn () => $this->record->realApplyCommandExecutionUrl())
                    ->openUrlInNewTab(),

                Action::make('openPostApplyCommand')
                    ->label('Open Post Apply Command')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (): bool => filled($this->record->post_apply_command_execution_id))
                    ->url(fn () => $this->record->postApplyCommandExecutionUrl())
                    ->openUrlInNewTab(),

                Action::make('openAuditLogs')
                    ->label('Open Audit Evidence')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn () => $this->record->relatedAuditLogsUrl())
                    ->openUrlInNewTab(),

                Action::make('createReplacementPlan')
                    ->label('Create Replacement Plan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => $this->record->canCreateReplacementPlan())
                    ->requiresConfirmation()
                    ->modalHeading('Create replacement apply plan?')
                    ->modalDescription('This generates a new LDIF apply plan from the same import batch. LDAP data will not be changed.')
                    ->schema([
                        Textarea::make('recovery_note')
                            ->label('Recovery Note')
                            ->rows(4)
                            ->default('Creating replacement plan after failed apply attempt.')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $result = app(ImportApplyPlanRecoveryService::class)->createReplacementPlan(
                            failedPlan: $this->record,
                            note: $data['recovery_note'] ?? '',
                        );

                        if (! $result['ok']) {
                            Notification::make()
                                ->title('Replacement failed')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        $replacement = $result['replacement_plan'];

                        Notification::make()
                            ->title('Replacement plan created')
                            ->body('Replacement Plan #'.$replacement->id.' was created. LDAP data was not changed.')
                            ->success()
                            ->send();
                    }),

                Action::make('archiveFailedPlan')
                    ->label('Archive Failed Plan')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (): bool => $this->record->canArchivePlan())
                    ->requiresConfirmation()
                    ->modalHeading('Archive failed apply plan?')
                    ->modalDescription('This marks the failed plan as archived. LDAP data will not be changed.')
                    ->schema([
                        Textarea::make('archive_reason')
                            ->label('Archive Reason')
                            ->rows(4)
                            ->default('Archived after replacement or recovery review.')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $result = app(ImportApplyPlanRecoveryService::class)->archiveFailedPlan(
                            plan: $this->record,
                            reason: $data['archive_reason'] ?? '',
                        );

                        if (! $result['ok']) {
                            Notification::make()
                                ->title('Archive failed')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Plan archived')
                            ->body($result['message'])
                            ->success()
                            ->send();

                        $this->refreshFormData([
                            'status',
                            'archived_at',
                            'archived_by',
                            'archive_reason',
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
            ])
                ->label('More')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button(),
        ];
    }
}
