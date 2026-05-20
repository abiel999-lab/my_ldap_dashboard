<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapUserMutationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Throwable;

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
                        ->options(fn (): array => $this->safeAddAttributeOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(fn ($get): HtmlString => $this->attributeInfo((string) $get('attribute'))),

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
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Select::make('attribute')
                        ->label('Attribute')
                        ->options(fn (): array => $this->safeReplaceAttributeOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(fn ($get): HtmlString => $this->attributeInfo((string) $get('attribute'))),

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

            Action::make('addObjectClass')
                ->label('Add ObjectClass')
                ->icon('heroicon-o-cube-transparent')
                ->color('info')
                ->form([
                    Select::make('objectClass')
                        ->label('ObjectClass')
                        ->options(fn (): array => $this->safeAddObjectClassOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Direct add hanya untuk AUXILIARY objectClass. STRUCTURAL nanti dibuat lewat Add User / Change Entry Type.'),

                    Placeholder::make('objectclass_note')
                        ->label('Note')
                        ->content(new HtmlString('Kalau objectClass punya MUST attribute, backend akan validasi dan log error. Input MUST dynamic akan kita rapikan setelah server stabil.')),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->addObjectClass(
                        $this->record,
                        (string) ($data['objectClass'] ?? ''),
                        []
                    );

                    $this->handleMutationResult($result);
                }),

            Action::make('removeObjectClass')
                ->label('Remove ObjectClass')
                ->icon('heroicon-o-cube')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove objectClass?')
                ->modalDescription('Dependent attributes akan ikut dihapus otomatis jika hanya berasal dari objectClass tersebut.')
                ->form([
                    Select::make('objectClass')
                        ->label('ObjectClass')
                        ->options(fn (): array => $this->safeRemoveObjectClassOptions())
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
                ->form([
                    Select::make('attribute')
                        ->label('Attribute')
                        ->options(fn (): array => $this->safeRemoveAttributeOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(fn ($get): HtmlString => $this->attributeInfo((string) $get('attribute'))),
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

    private function safeAddAttributeOptions(): array
    {
        try {
            return app(LdapSchemaAttributeCatalogService::class)->addAttributeOptions($this->record);
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function safeReplaceAttributeOptions(): array
    {
        try {
            return app(LdapSchemaAttributeCatalogService::class)->replaceAttributeOptions($this->record);
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function safeRemoveAttributeOptions(): array
    {
        try {
            return app(LdapSchemaAttributeCatalogService::class)->removeAttributeOptions($this->record);
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function safeAddObjectClassOptions(): array
    {
        try {
            $service = app(LdapSchemaAttributeCatalogService::class);

            if (method_exists($service, 'directAddObjectClassOptions')) {
                return $service->directAddObjectClassOptions($this->record);
            }

            return $service->addObjectClassOptions($this->record);
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function safeRemoveObjectClassOptions(): array
    {
        try {
            return app(LdapSchemaAttributeCatalogService::class)->removeObjectClassOptions($this->record);
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function attributeInfo(string $attribute): HtmlString
    {
        if ($attribute === '') {
            return new HtmlString('<span class="text-gray-400">Choose an attribute first.</span>');
        }

        try {
            $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($this->record, $attribute);

            $type = ($meta['single_value'] ?? false) ? 'single-value' : 'multi-value';
            $required = ($meta['required'] ?? false) ? 'required' : 'optional';
            $valueType = $meta['value_type'] ?? 'unknown';
            $syntax = $meta['syntax_oid'] ?? 'unknown syntax';
            $sources = implode(', ', array_slice($meta['source_object_classes'] ?? [], 0, 5));

            return new HtmlString(e(
                'Type: '.$valueType.
                ' | Cardinality: '.$type.
                ' | Requirement: '.$required.
                ' | Syntax: '.$syntax.
                ($sources ? ' | From: '.$sources : '')
            ));
        } catch (Throwable $e) {
            report($e);
            return new HtmlString('<span class="text-danger-500">Failed to load attribute metadata.</span>');
        }
    }

    private function handleMutationResult(array $result): void
    {
        if ($result['ok'] ?? false) {
            $this->record->refresh();

            Notification::make()
                ->title($result['message'] ?? 'LDAP entry updated.')
                ->body(isset($result['command_execution_id']) ? 'Command Execution ID: '.$result['command_execution_id'] : null)
                ->success()
                ->send();

            $this->dispatch('$refresh');

            return;
        }

        Notification::make()
            ->title('LDAP entry update failed')
            ->body($result['message'] ?? 'Unknown error.')
            ->danger()
            ->send();
    }
}
