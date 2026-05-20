<?php

namespace App\Filament\Resources\Operations\SavedScriptResource\Pages;

use App\Filament\Resources\Operations\SavedScriptResource;
use App\Services\Operations\ScriptOperationDispatcher;
use App\Services\Operations\ScriptPreviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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

            Action::make('executeLdapSearch')
                ->label('Queue ldapsearch')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->record->script_type === 'ldapsearch' && ! $this->record->destructive)
                ->requiresConfirmation()
                ->modalHeading('Queue ldapsearch in safe mode?')
                ->modalDescription('This creates an Operation Job and runs read-only ldapsearch in the script queue. It will not modify LDAP data.')
                ->action(function (): void {
                    $operationJob = app(ScriptOperationDispatcher::class)->queueLdapSearch($this->record);

                    Notification::make()
                        ->title('ldapsearch queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. Check Operation Jobs for progress.')
                        ->success()
                        ->send();
                }),
        ];
    }


    public static function canAccess(): bool
    {
        return false;
    }
}
