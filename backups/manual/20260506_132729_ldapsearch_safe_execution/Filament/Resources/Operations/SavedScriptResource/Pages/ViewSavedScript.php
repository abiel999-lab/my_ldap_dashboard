<?php

namespace App\Filament\Resources\Operations\SavedScriptResource\Pages;

use App\Filament\Resources\Operations\SavedScriptResource;
use App\Services\Operations\ScriptPreviewService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class ViewSavedScript extends ViewRecord
{
    protected static string $resource = SavedScriptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewScript')
                ->label('Preview Script')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Preview script?')
                ->modalDescription('This validates and previews the script without executing destructive changes.')
                ->action(function (): void {
                    $execution = app(ScriptPreviewService::class)->preview($this->record);

                    Notification::make()
                        ->title($execution->status === 'previewed' ? 'Script preview created' : 'Script preview blocked')
                        ->body('Command Execution #'.$execution->id.' status: '.$execution->status)
                        ->{$execution->status === 'previewed' ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }
}
