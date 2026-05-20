<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapUserMutationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

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
                        ->live()
                        ->helperText('Only attributes allowed by the current objectClass set and not yet present are shown.'),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(function ($get): HtmlString {
                            $attribute = (string) $get('attribute');

                            if ($attribute === '') {
                                return new HtmlString('<span class="text-gray-400">Choose an attribute first.</span>');
                            }

                            $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($this->record, $attribute);

                            return new HtmlString(e($this->formatAttributeMeta($meta)));
                        }),

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
                        ->live()
                        ->helperText('Only existing editable attributes are shown.'),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(function ($get): HtmlString {
                            $attribute = (string) $get('attribute');

                            if ($attribute === '') {
                                return new HtmlString('<span class="text-gray-400">Choose an attribute first.</span>');
                            }

                            $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($this->record, $attribute);

                            return new HtmlString(e($this->formatAttributeMeta($meta)));
                        }),

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
                        ->options(fn (): array => app(LdapSchemaAttributeCatalogService::class)->directAddObjectClassOptions($this->record))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->helperText('Direct add only supports AUXILIARY objectClass. STRUCTURAL changes require Change Entry Type / Rebuild Entry.'),

                    Placeholder::make('objectclass_info')
                        ->label('ObjectClass Requirement')
                        ->content(function ($get): HtmlString {
                            $objectClass = (string) $get('objectClass');

                            if ($objectClass === '') {
                                return new HtmlString('<span class="text-gray-400">Choose an objectClass first.</span>');
                            }

                            $missingMust = app(LdapSchemaAttributeCatalogService::class)->missingMustAttributesForObjectClass($this->record, $objectClass);

                            if ($missingMust === []) {
                                return new HtmlString('<span class="text-success-500">No missing MUST attributes. You can submit directly.</span>');
                            }

                            $names = collect($missingMust)
                                ->map(fn (array $meta, string $name): string => $name.' ('.($meta['value_type'] ?? 'unknown').')')
                                ->values()
                                ->implode(', ');

                            return new HtmlString(e('Missing MUST attributes: '.$names));
                        }),

                    Grid::make(1)
                        ->schema(function ($get): array {
                            $objectClass = (string) $get('objectClass');

                            if ($objectClass === '') {
                                return [];
                            }

                            $missingMust = app(LdapSchemaAttributeCatalogService::class)
                                ->missingMustAttributesForObjectClass($this->record, $objectClass);

                            $fields = [];

                            foreach ($missingMust as $attribute => $meta) {
                                $fields[] = TagsInput::make('must_values.'.$attribute)
                                    ->label('MUST: '.$attribute)
                                    ->placeholder('Type value then press Enter')
                                    ->required()
                                    ->helperText($this->formatAttributeMeta($meta));
                            }

                            return $fields;
                        }),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserMutationService::class)->addObjectClass(
                        $this->record,
                        (string) ($data['objectClass'] ?? ''),
                        $data['must_values'] ?? []
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
                        ->required()
                        ->live(),

                    Placeholder::make('attribute_info')
                        ->label('Attribute Type')
                        ->content(function ($get): HtmlString {
                            $attribute = (string) $get('attribute');

                            if ($attribute === '') {
                                return new HtmlString('<span class="text-gray-400">Choose an attribute first.</span>');
                            }

                            $meta = app(LdapSchemaAttributeCatalogService::class)->attributeMetadataForUser($this->record, $attribute);

                            return new HtmlString(e($this->formatAttributeMeta($meta)));
                        }),
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

    private function formatAttributeMeta(array $meta): string
    {
        $type = ($meta['single_value'] ?? false) ? 'single-value' : 'multi-value';
        $required = ($meta['required'] ?? false) ? 'required' : 'optional';
        $valueType = $meta['value_type'] ?? 'unknown';
        $syntax = $meta['syntax_oid'] ?? 'unknown syntax';
        $sources = implode(', ', array_slice($meta['source_object_classes'] ?? [], 0, 5));

        return 'Type: '.$valueType.' | Cardinality: '.$type.' | Requirement: '.$required.' | Syntax: '.$syntax.($sources ? ' | From: '.$sources : '');
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
