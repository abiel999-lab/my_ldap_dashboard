<?php

namespace App\Filament\Resources\Operations\LdifExportBatchResource\Pages;

use App\Filament\Resources\Operations\LdifExportBatchResource;
use App\Services\Operations\LdifExportDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
                    $operationJob = app(LdifExportDispatcher::class)->queueExport($this->record);

                    Notification::make()
                        ->title('LDIF export queued')
                        ->body('Operation Job #'.$operationJob->id.' was created.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
