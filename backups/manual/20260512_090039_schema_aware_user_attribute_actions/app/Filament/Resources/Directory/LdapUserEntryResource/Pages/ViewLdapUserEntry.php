<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapUserMutationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapUserEntry extends ViewRecord
{
    protected static string $resource = LdapUserEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addAttribute')
                ->label('Add Attribute')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    TextInput::make('attribute')
                        ->label('Attribute Name')
                        ->placeholder('example: petraVlan')
                        ->required(),

                    TagsInput::make('values')
                        ->label('Values')
                        ->placeholder('Type value then press Enter')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->addAttribute(
                        $this->record,
                        (string) ($data['attribute'] ?? ''),
                        $data['values'] ?? []
                    );

                    $this->handleMutationResult($result);
                }),

            Action::make('replaceAttribute')
                ->label('Replace Attribute')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->form([
                    TextInput::make('attribute')
                        ->label('Attribute Name')
                        ->placeholder('example: displayName')
                        ->required(),

                    TagsInput::make('values')
                        ->label('New Values')
                        ->placeholder('Type value then press Enter')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->replaceAttribute(
                        $this->record,
                        (string) ($data['attribute'] ?? ''),
                        $data['values'] ?? []
                    );

                    $this->handleMutationResult($result);
                }),

            Action::make('removeAttribute')
                ->label('Remove Attribute')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove LDAP attribute?')
                ->modalDescription('This will remove the entire attribute from the LDAP entry.')
                ->form([
                    TextInput::make('attribute')
                        ->label('Attribute Name')
                        ->placeholder('example: description')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->removeAttribute(
                        $this->record,
                        (string) ($data['attribute'] ?? '')
                    );

                    $this->handleMutationResult($result);
                }),

            EditAction::make(),
        ];
    }

    private function handleMutationResult(array $result): void
    {
        if ($result['ok'] ?? false) {
            $this->record->refresh();

            Notification::make()
                ->title($result['message'] ?? 'LDAP attribute updated.')
                ->body(isset($result['command_execution_id']) ? 'Command Execution ID: '.$result['command_execution_id'] : null)
                ->success()
                ->send();

            $this->dispatch('$refresh');

            return;
        }

        Notification::make()
            ->title('LDAP attribute update failed')
            ->body($result['message'] ?? 'Unknown error.')
            ->danger()
            ->send();
    }
}
