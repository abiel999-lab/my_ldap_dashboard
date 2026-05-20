<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Models\Directory\LdapConnection;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapUserLifecycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Throwable;

class ListLdapUserEntries extends ListRecords
{
    protected static string $resource = LdapUserEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createLdapUser')
                ->label('Add LDAP User')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('ldap_connection_id')
                        ->label('LDAP Connection')
                        ->options(fn (): array => LdapConnection::query()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    TextInput::make('parent_dn')
                        ->label('Parent DN')
                        ->default('ou=people,dc=petra,dc=ac,dc=id')
                        ->required(),

                    TextInput::make('rdn_attribute')
                        ->label('RDN Attribute')
                        ->default('uid')
                        ->required(),

                    TextInput::make('rdn_value')
                        ->label('RDN Value')
                        ->placeholder('new.user001')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set): void {
                            if ($state) {
                                $set('attributes.uid', (string) $state);
                            }
                        }),

                    Select::make('structural_object_class')
                        ->label('Structural ObjectClass')
                        ->options(function ($get): array {
                            $connectionId = (int) $get('ldap_connection_id');

                            if ($connectionId <= 0) {
                                return [];
                            }

                            try {
                                return app(LdapSchemaAttributeCatalogService::class)
                                    ->structuralObjectClassOptionsForConnection($connectionId);
                            } catch (Throwable $e) {
                                report($e);
                                return [];
                            }
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get): void {
                            $connectionId = (int) $get('ldap_connection_id');
                            $objectClass = (string) $state;

                            if ($connectionId <= 0 || $objectClass === '') {
                                $set('attributes', []);
                                return;
                            }

                            try {
                                $must = app(LdapSchemaAttributeCatalogService::class)
                                    ->mustAttributesForObjectClassOnConnection($connectionId, $objectClass);

                                $attributes = [];

                                foreach (array_keys($must) as $attribute) {
                                    $attributes[$attribute] = '';
                                }

                                $rdnAttribute = (string) $get('rdn_attribute');
                                $rdnValue = (string) $get('rdn_value');

                                if ($rdnAttribute !== '' && $rdnValue !== '') {
                                    $attributes[$rdnAttribute] = $rdnValue;
                                }

                                $set('attributes', $attributes);
                            } catch (Throwable $e) {
                                report($e);
                                $set('attributes', []);
                            }
                        }),

                    Placeholder::make('must_info')
                        ->label('MUST Attributes')
                        ->content(function ($get): HtmlString {
                            $connectionId = (int) $get('ldap_connection_id');
                            $objectClass = (string) $get('structural_object_class');

                            if ($connectionId <= 0 || $objectClass === '') {
                                return new HtmlString('<span class="text-gray-400">Choose LDAP connection and structural objectClass first.</span>');
                            }

                            try {
                                $must = app(LdapSchemaAttributeCatalogService::class)
                                    ->mustAttributesForObjectClassOnConnection($connectionId, $objectClass);

                                if ($must === []) {
                                    return new HtmlString('<span class="text-success-500">No MUST attributes found.</span>');
                                }

                                $html = '<div class="space-y-1">';

                                foreach ($must as $name => $meta) {
                                    $html .= '<div>- <b>'.e($name).'</b> <span class="text-gray-400">('.e($meta['value_type'] ?? 'unknown').')</span></div>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            } catch (Throwable $e) {
                                report($e);
                                return new HtmlString('<span class="text-danger-500">Failed to load MUST attributes.</span>');
                            }
                        }),

                    TagsInput::make('auxiliary_object_classes')
                        ->label('Auxiliary ObjectClasses')
                        ->placeholder('Optional: type objectClass then Enter')
                        ->helperText('Opsional. Contoh: petraPerson, sambaSamAccount.'),

                    KeyValue::make('attributes')
                        ->label('LDAP Attributes')
                        ->keyLabel('Attribute')
                        ->valueLabel('Value')
                        ->helperText('MUST attribute otomatis muncul setelah pilih structural objectClass. Multi-value pisahkan dengan koma.'),
                ])
                ->action(function (array $data): void {
                    $result = app(LdapUserLifecycleService::class)->createUser($data);

                    if ($result['ok'] ?? false) {
                        Notification::make()
                            ->title($result['message'] ?? 'LDAP user created.')
                            ->body(isset($result['command_execution_id']) ? 'Command Execution ID: '.$result['command_execution_id'] : null)
                            ->success()
                            ->send();

                        $this->dispatch('$refresh');

                        return;
                    }

                    Notification::make()
                        ->title('Create LDAP user failed')
                        ->body($result['message'] ?? 'Unknown error.')
                        ->danger()
                        ->send();
                }),
        ];
    }
}
