<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapUserMutationService;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
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
                        ->live()
                        ->helperText('Direct add hanya untuk AUXILIARY objectClass. STRUCTURAL nanti dibuat lewat Add User / Change Entry Type.'),

                    Placeholder::make('objectclass_note')
                        ->label('MUST Requirement')
                        ->content(function ($get): HtmlString {
                            $objectClass = (string) $get('objectClass');

                            if ($objectClass === '') {
                                return new HtmlString('<span class="text-gray-400">Choose an objectClass first.</span>');
                            }

                            try {
                                $missingMust = app(LdapSchemaAttributeCatalogService::class)
                                    ->missingMustAttributesForObjectClass($this->record, $objectClass);

                                if ($missingMust === []) {
                                    return new HtmlString('<span class="text-success-500">No missing MUST attributes. You can submit directly.</span>');
                                }

                                $html = '<div class="space-y-1">';
                                $html .= '<div class="font-semibold text-warning-500">Missing MUST attributes:</div>';

                                foreach ($missingMust as $name => $meta) {
                                    $type = $meta['value_type'] ?? 'unknown';
                                    $single = ($meta['single_value'] ?? false) ? 'single-value' : 'multi-value';
                                    $html .= '<div>- <b>'.e($name).'</b> <span class="text-gray-400">('.e($type).' / '.e($single).')</span></div>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            } catch (Throwable $e) {
                                report($e);
                                return new HtmlString('<span class="text-danger-500">Failed to load MUST attributes.</span>');
                            }
                        }),

                    KeyValue::make('must_values')
                        ->label('MUST Attribute Values')
                        ->keyLabel('Attribute name')
                        ->valueLabel('Value')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->live()
                        ->helperText('Nama atribut MUST otomatis terisi. User hanya perlu isi value. Untuk multi-value, pisahkan dengan koma.')
                        ->afterStateHydrated(function ($component, $state, $get): void {
                            $objectClass = (string) $get('objectClass');

                            if ($objectClass === '') {
                                return;
                            }

                            try {
                                $missingMust = app(LdapSchemaAttributeCatalogService::class)
                                    ->missingMustAttributesForObjectClass($this->record, $objectClass);

                                if ($missingMust === []) {
                                    $component->state([]);
                                    return;
                                }

                                $autoState = [];

                                foreach (array_keys($missingMust) as $attribute) {
                                    $autoState[$attribute] = is_array($state) && array_key_exists($attribute, $state)
                                        ? $state[$attribute]
                                        : '';
                                }

                                $component->state($autoState);
                            } catch (Throwable $e) {
                                report($e);
                            }
                        })
                        ->visible(function ($get): bool {
                            $objectClass = (string) $get('objectClass');

                            if ($objectClass === '') {
                                return false;
                            }

                            try {
                                return app(LdapSchemaAttributeCatalogService::class)
                                    ->missingMustAttributesForObjectClass($this->record, $objectClass) !== [];
                            } catch (Throwable $e) {
                                report($e);
                                return false;
                            }
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
