<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Services\Directory\LdapSchemaAttributeCatalogService;
use App\Services\Directory\LdapSingleUserSyncService;
use App\Services\Directory\LdapUserLifecycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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
            Action::make('syncAllUsers')
                ->label('Sync All Users')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Sync all visible users?')
                ->modalDescription('Sistem akan melakukan sync per user untuk semua user aktif yang tampil di default list. Semua hasil masuk Command Executions.')
                ->action(function (): void {
                    $users = LdapUserEntry::query()
                        ->where(function ($query): void {
                            $query
                                ->whereNull('status')
                                ->orWhereNotIn('status', [
                                    'missing_from_ldap',
                                    'deleted_from_ldap',
                                ]);
                        })
                        ->orderBy('id')
                        ->get();

                    $ok = 0;
                    $failed = 0;

                    foreach ($users as $user) {
                        $result = app(LdapSingleUserSyncService::class)->sync($user);

                        if ($result['ok'] ?? false) {
                            $ok++;
                        } else {
                            $failed++;
                        }
                    }

                    Notification::make()
                        ->title('Sync all users finished')
                        ->body('Success: '.$ok.' | Failed: '.$failed)
                        ->success()
                        ->send();

                    $this->dispatch('$refresh');
                }),

            Action::make('createLdapUser')
                ->label('Add LDAP User')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('ldap_connection_id')
                        ->label('LDAP Connection')
                        ->options(fn (): array => LdapConnection::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->default(fn (): ?int => app(LdapSchemaAttributeCatalogService::class)->defaultConnectionId())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set): void {
                            $set('structural_object_class', null);
                            $set('auxiliary_object_classes', []);
                            $set('attributes', []);
                        }),

                    TextInput::make('parent_dn')
                        ->label('Parent DN')
                        ->default('ou=people,dc=petra,dc=ac,dc=id')
                        ->required(),

                    TextInput::make('rdn_attribute')
                        ->label('RDN Attribute')
                        ->default('uid')
                        ->required()
                        ->live(),

                    TextInput::make('rdn_value')
                        ->label('RDN Value')
                        ->placeholder('new.user001')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get): void {
                            $rdnAttribute = (string) ($get('rdn_attribute') ?: 'uid');

                            if ($state && $rdnAttribute !== '') {
                                $set('attributes.'.$rdnAttribute, (string) $state);
                            }
                        }),

                    Select::make('structural_object_class')
                        ->label('Structural ObjectClass')
                        ->options(function ($get): array {
                            $connectionId = (int) ($get('ldap_connection_id') ?: 0);

                            return app(LdapSchemaAttributeCatalogService::class)
                                ->structuralObjectClassOptionsForConnectionWithFallback($connectionId);
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get): void {
                            $connectionId = (int) ($get('ldap_connection_id') ?: app(LdapSchemaAttributeCatalogService::class)->defaultConnectionId());
                            $objectClass = (string) $state;

                            if ($connectionId <= 0 || $objectClass === '') {
                                $set('attributes', []);
                                return;
                            }

                            try {
                                $must = app(LdapSchemaAttributeCatalogService::class)
                                    ->mustAttributesForObjectClassOnConnection($connectionId, $objectClass);

                                if ($must === []) {
                                    $fallbackMust = match ($objectClass) {
                                        'inetOrgPerson', 'organizationalPerson', 'person' => ['cn' => '', 'sn' => ''],
                                        'account' => ['uid' => ''],
                                        'organizationalUnit' => ['ou' => ''],
                                        'groupOfNames' => ['cn' => '', 'member' => ''],
                                        'groupOfUniqueNames' => ['cn' => '', 'uniqueMember' => ''],
                                        default => [],
                                    };

                                    $attributes = $fallbackMust;
                                } else {
                                    $attributes = [];

                                    foreach (array_keys($must) as $attribute) {
                                        $attributes[$attribute] = '';
                                    }
                                }

                                $rdnAttribute = (string) ($get('rdn_attribute') ?: 'uid');
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
                            $connectionId = (int) ($get('ldap_connection_id') ?: app(LdapSchemaAttributeCatalogService::class)->defaultConnectionId());
                            $objectClass = (string) $get('structural_object_class');

                            if ($connectionId <= 0 || $objectClass === '') {
                                return new HtmlString('<span class="text-gray-400">Choose structural objectClass first.</span>');
                            }

                            try {
                                $must = app(LdapSchemaAttributeCatalogService::class)
                                    ->mustAttributesForObjectClassOnConnection($connectionId, $objectClass);

                                if ($must === []) {
                                    $fallback = match ($objectClass) {
                                        'inetOrgPerson', 'organizationalPerson', 'person' => ['cn', 'sn'],
                                        'account' => ['uid'],
                                        'organizationalUnit' => ['ou'],
                                        'groupOfNames' => ['cn', 'member'],
                                        'groupOfUniqueNames' => ['cn', 'uniqueMember'],
                                        default => [],
                                    };

                                    if ($fallback === []) {
                                        return new HtmlString('<span class="text-gray-400">No MUST metadata found from schema.</span>');
                                    }

                                    return new HtmlString('<span class="text-warning-500">Fallback MUST: '.e(implode(', ', $fallback)).'</span>');
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

                    Select::make('auxiliary_object_classes')
                        ->label('Auxiliary ObjectClasses')
                        ->multiple()
                        ->options(function ($get): array {
                            $connectionId = (int) ($get('ldap_connection_id') ?: 0);

                            return app(LdapSchemaAttributeCatalogService::class)
                                ->auxiliaryObjectClassOptionsForConnectionWithFallback($connectionId);
                        })
                        ->searchable()
                        ->preload()
                        ->helperText('Opsional. Pilih auxiliary objectClass dari schema.'),

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
