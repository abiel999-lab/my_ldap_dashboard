<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapUserMutationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                    Select::make('attribute')
                        ->label('Attribute')
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->addAttributeOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Only attributes allowed by the current objectClass set and not yet present are shown.'),

                    TagsInput::make('values')
                        ->label('Values')
                        ->placeholder('Type value then press Enter')
                        ->required()
                        ->helperText('If the selected attribute is single-value, only one value is allowed.'),
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
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Select::make('attribute')
                        ->label('Attribute')
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->replaceAttributeOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Only existing editable attributes are shown.'),

                    TagsInput::make('values')
                        ->label('New Values')
                        ->placeholder('Type value then press Enter')
                        ->required()
                        ->helperText('This replaces the entire value set of the selected attribute.'),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->replaceAttribute(
                        $this->record,
                        (string) ($data['attribute'] ?? ''),
                        $data['values'] ?? []
                    );

                    $this->handleMutationResult($result);
                }),


            Action::make('addObjectClass')
                ->label('Add ObjectClass')
                ->icon('heroicon-o-cube-transparent')
                ->color('info')
                ->form([
                    Select::make('objectClass')
                        ->label('ObjectClass')
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->addObjectClassOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Only objectClasses found in LDAP schema and not yet attached are shown.'),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->addObjectClass(
                        $this->record,
                        (string) ($data['objectClass'] ?? '')
                    );

                    $this->handleMutationResult($result);
                }),

            Action::make('removeObjectClass')
                ->label('Remove ObjectClass')
                ->icon('heroicon-o-cube')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove objectClass?')
                ->modalDescription('This removes the selected auxiliary objectClass from the LDAP entry. Structural and protected objectClasses are not listed.')
                ->form([
                    Select::make('objectClass')
                        ->label('ObjectClass')
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->removeObjectClassOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->removeObjectClass(
                        $this->record,
                        (string) ($data['objectClass'] ?? '')
                    );

                    $this->handleMutationResult($result);
                }),

            Action::make('removeAttribute')
                ->label('Remove Attribute')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove LDAP attribute?')
                ->modalDescription('This removes the entire selected attribute from the LDAP entry. Required, protected, and read-only attributes are not listed.')
                ->form([
                    Select::make('attribute')
                        ->label('Attribute')
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->removeAttributeOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->removeAttribute(
                        $this->record,
                        (string) ($data['attribute'] ?? '')
                    );

                    $this->handleMutationResult($result);
                }),
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
