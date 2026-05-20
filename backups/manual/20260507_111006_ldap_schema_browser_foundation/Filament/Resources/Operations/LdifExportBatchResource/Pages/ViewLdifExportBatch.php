<?php

namespace App\Filament\Resources\Operations\LdifExportBatchResource\Pages;

use App\Filament\Resources\Operations\LdifExportBatchResource;
use App\Models\Operations\LdifExportBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\LdifExportDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Response;

class ViewLdifExportBatch extends ViewRecord
{
    protected static string $resource = LdifExportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('queueExport')
                ->label('Queue Export')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, ['draft', 'failed'], true))
                ->requiresConfirmation()
                ->modalHeading('Queue LDIF export?')
                ->modalDescription('This creates an Operation Job and runs read-only ldapsearch export in the export queue.')
                ->action(function (): void {
                    $result = app(LdifExportDispatcher::class)->queueExport($this->record);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDIF export')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDIF export queued')
                        ->body('Operation Job #'.$operationJob->id.' was created.')
                        ->success()
                        ->send();
                }),

            Action::make('downloadLdif')
                ->label('Download LDIF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (): bool => $this->record->hasOutputFile())
                ->action(function () {
                    /** @var LdifExportBatch $record */
                    $record = $this->record;

                    if (! $record->hasOutputFile()) {
                        Notification::make()
                            ->title('LDIF file missing')
                            ->body('The export file does not exist in storage.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    app(AuditLogger::class)->log([
                        'module' => 'operations.export',
                        'action' => 'download_ldif_export',
                        'status' => 'success',
                        'target_type' => LdifExportBatch::class,
                        'target_key' => (string) $record->id,
                        'target_dn' => $record->base_dn,
                        'ldap_connection_id' => $record->ldap_connection_id,
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

            Action::make('auditViewFile')
                ->label('Audit View File')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->record->hasOutputFile())
                ->action(function (): void {
                    /** @var LdifExportBatch $record */
                    $record = $this->record;

                    app(AuditLogger::class)->log([
                        'module' => 'operations.export',
                        'action' => 'view_ldif_export_content',
                        'status' => 'success',
                        'target_type' => LdifExportBatch::class,
                        'target_key' => (string) $record->id,
                        'target_dn' => $record->base_dn,
                        'ldap_connection_id' => $record->ldap_connection_id,
                        'operation_job_id' => $record->operation_job_id,
                        'request_payload' => [
                            'output_path' => $record->output_path,
                            'output_size_bytes' => $record->output_size_bytes,
                            'output_hash' => $record->output_hash,
                        ],
                    ]);

                    Notification::make()
                        ->title('LDIF view audited')
                        ->body('This file view has been recorded in audit logs.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
