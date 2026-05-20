<?php

namespace App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;

use App\Filament\Resources\Operations\ImportApplyPlanResource;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Response;

class ViewImportApplyPlan extends ViewRecord
{
    protected static string $resource = ImportApplyPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
