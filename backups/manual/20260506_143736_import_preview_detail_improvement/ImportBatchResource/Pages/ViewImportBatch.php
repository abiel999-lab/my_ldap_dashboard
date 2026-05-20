<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
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
        ];
    }
}
