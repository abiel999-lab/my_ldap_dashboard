<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\ImportApplyPlanDispatcher;
use App\Services\Operations\ImportPreviewDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('queuePreview')
                ->label('Queue Preview')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->record->status, ['draft', 'failed', 'previewed_with_errors'], true))
                ->requiresConfirmation()
                ->modalHeading('Queue import preview?')
                ->modalDescription('This parses and validates the uploaded file in the import queue. No LDAP data will be changed.')
                ->action(function (): void {
                    $result = app(ImportPreviewDispatcher::class)->queuePreview($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue import preview')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('Import preview queued')
                        ->body('Operation Job #'.$operationJob->id.' was created.')
                        ->success()
                        ->send();
                }),

            Action::make('generateApplyLdifDryRun')
                ->label('Generate Apply LDIF')
                ->icon('heroicon-o-document-check')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->status, ['previewed', 'previewed_with_errors', 'ready_to_apply'], true) && $this->record->valid_rows > 0)
                ->requiresConfirmation()
                ->modalHeading('Generate apply LDIF dry run?')
                ->modalDescription('This generates an LDIF apply plan file from valid preview rows. It will not change LDAP data.')
                ->action(function (): void {
                    $result = app(ImportApplyPlanDispatcher::class)->queueGenerateLdifDryRun($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue apply LDIF generation')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];
                    $plan = $result['plan'];

                    Notification::make()
                        ->title('Apply LDIF generation queued')
                        ->body('Operation Job #'.$operationJob->id.' and Apply Plan #'.$plan->id.' were created. LDAP was not changed.')
                        ->success()
                        ->send();
                }),

            Action::make('markReadyToApply')
                ->label('Mark Ready To Apply')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === 'previewed' && $this->record->invalid_rows === 0 && $this->record->duplicate_rows === 0)
                ->requiresConfirmation()
                ->modalHeading('Mark import as ready to apply?')
                ->modalDescription('This only marks the import as ready. It will not apply changes to LDAP yet.')
                ->action(function (): void {
                    $this->record->forceFill([
                        'status' => 'ready_to_apply',
                        'message' => 'Import batch marked ready to apply. LDAP data has not been changed yet.',
                    ])->save();

                    app(AuditLogger::class)->log([
                        'module' => 'operations.import',
                        'action' => 'mark_import_ready_to_apply',
                        'status' => 'success',
                        'target_type' => $this->record::class,
                        'target_key' => (string) $this->record->id,
                        'target_dn' => $this->record->base_dn,
                        'ldap_connection_id' => $this->record->ldap_connection_id,
                        'operation_job_id' => $this->record->operation_job_id,
                        'request_payload' => [
                            'total_rows' => $this->record->total_rows,
                            'valid_rows' => $this->record->valid_rows,
                            'invalid_rows' => $this->record->invalid_rows,
                            'duplicate_rows' => $this->record->duplicate_rows,
                            'will_create_rows' => $this->record->will_create_rows,
                            'will_update_rows' => $this->record->will_update_rows,
                            'will_skip_rows' => $this->record->will_skip_rows,
                            'will_fail_rows' => $this->record->will_fail_rows,
                        ],
                    ]);

                    Notification::make()
                        ->title('Import marked ready')
                        ->body('This batch is ready to apply later. No LDAP data has been changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
