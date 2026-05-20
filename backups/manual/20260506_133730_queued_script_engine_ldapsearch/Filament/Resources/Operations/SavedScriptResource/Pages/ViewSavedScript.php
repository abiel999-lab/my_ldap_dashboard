<?php

namespace App\Filament\Resources\Operations\SavedScriptResource\Pages;

use App\Filament\Resources\Operations\SavedScriptResource;
use App\Services\Operations\LdapSearchScriptExecutor;
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

            Action::make('executeLdapSearch')
                ->label('Execute ldapsearch')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->record->script_type === 'ldapsearch' && ! $this->record->destructive)
                ->requiresConfirmation()
                ->modalHeading('Execute ldapsearch in safe mode?')
                ->modalDescription('This runs a read-only ldapsearch using the default LDAP connection. It will not modify LDAP data.')
                ->action(function (): void {
                    $execution = app(LdapSearchScriptExecutor::class)->execute($this->record);

                    Notification::make()
                        ->title($execution->status === 'success' ? 'ldapsearch executed' : 'ldapsearch failed or blocked')
                        ->body('Command Execution #'.$execution->id.' status: '.$execution->status)
                        ->{$execution->status === 'success' ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }
}
