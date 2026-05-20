<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Jobs\Directory\CreateLdapUserJob;
use App\Jobs\Directory\SyncUsersBatchJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Services\Directory\DirectoryManagementSyncDispatcher;
use App\Support\Operations\SafeCommandExecutionLogger;
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
                ->modalHeading('Queue LDAP users sync?')
                ->modalDescription('Sync ini memakai Universal LDAP Sync Engine yang sama dengan LDAP Sync Center. Data akan masuk queue, Operation Jobs, dan Operation Job Logs.')
                ->action(function (): void {
                    $result = app(DirectoryManagementSyncDispatcher::class)->queueUsers();

                    DirectoryManagementSyncDispatcher::notifyResult($result, 'LDAP users sync queued');
                }),

            Action::make('createLdapUser')
                ->label('Add LDAP User')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('ldap_connection_id')
                        ->label('LDAP Connection')
                        ->options(fn (): array => LdapConnection::query()
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn ($connection): array => [
                                $connection->id => ($connection->name ?? ('Connection #'.$connection->id)),
                            ])
                            ->toArray())
                        ->default(fn (): ?int => LdapConnection::query()->orderBy('id')->value('id'))
                        ->searchable()
                        ->preload()
                        ->required(),

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

                            if ($rdnAttribute !== '' && $state !== null && $state !== '') {
                                $set('attributes.'.$rdnAttribute, (string) $state);
                            }
                        }),

                    Select::make('structural_object_class')
                        ->label('Structural ObjectClass')
                        ->options(fn (): array => self::structuralObjectClassOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get): void {
                            $objectClass = (string) $state;
                            $attributes = self::mustAttributesForStructural($objectClass);

                            $rdnAttribute = (string) ($get('rdn_attribute') ?: 'uid');
                            $rdnValue = (string) $get('rdn_value');

                            if ($rdnAttribute !== '' && $rdnValue !== '') {
                                $attributes[$rdnAttribute] = $rdnValue;
                            }

                            $set('attributes', $attributes);
                        }),

                    Placeholder::make('must_info')
                        ->label('MUST Attributes')
                        ->content(function ($get): HtmlString {
                            $objectClass = (string) $get('structural_object_class');

                            if ($objectClass === '') {
                                return new HtmlString('<span class="text-gray-400">Choose structural objectClass first.</span>');
                            }

                            $must = array_keys(self::mustAttributesForStructural($objectClass));

                            if ($must === []) {
                                return new HtmlString('<span class="text-gray-400">No MUST attributes found.</span>');
                            }

                            return new HtmlString('<span class="text-warning-500">Required: '.e(implode(', ', $must)).'</span>');
                        }),

                    Select::make('auxiliary_object_classes')
                        ->label('Auxiliary ObjectClasses')
                        ->multiple()
                        ->options(fn (): array => self::auxiliaryObjectClassOptions())
                        ->searchable()
                        ->preload()
                        ->helperText('Opsional. Pilih auxiliary objectClass.'),

                    KeyValue::make('attributes')
                        ->label('LDAP Attributes')
                        ->keyLabel('Attribute')
                        ->valueLabel('Value')
                        ->helperText('MUST attribute otomatis muncul setelah pilih structural objectClass. Multi-value pisahkan dengan koma.'),
                ])
                ->action(function (array $data): void {
                    try {
                        $data = self::normalizeCreatePayload($data);

                        $execution = SafeCommandExecutionLogger::createQueued(
                            'ldap_user_create_queued',
                            'queued job: CreateLdapUserJob',
                            [
                                'operation' => 'create_ldap_user',
                                'queue' => 'ldap',
                                'payload' => self::safePayloadForLog($data),
                            ]
                        );

                        CreateLdapUserJob::dispatch($data, SafeCommandExecutionLogger::id($execution));

                        Notification::make()
                            ->title('Create LDAP user queued')
                            ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        SafeCommandExecutionLogger::createFailed('ldap_user_create_dispatch_failed', $e->getMessage(), [
                            'payload' => self::safePayloadForLog($data ?? []),
                        ]);

                        Notification::make()
                            ->title('Create LDAP user dispatch failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    private static function structuralObjectClassOptions(): array
    {
        return [
            'inetOrgPerson' => 'inetOrgPerson — STRUCTURAL — user account — MUST: cn, sn',
            'organizationalPerson' => 'organizationalPerson — STRUCTURAL — MUST: cn, sn',
            'person' => 'person — STRUCTURAL — MUST: cn, sn',
            'account' => 'account — STRUCTURAL — MUST: uid',
            'organizationalUnit' => 'organizationalUnit — STRUCTURAL — MUST: ou',
            'groupOfNames' => 'groupOfNames — STRUCTURAL — MUST: cn, member',
            'groupOfUniqueNames' => 'groupOfUniqueNames — STRUCTURAL — MUST: cn, uniqueMember',
        ];
    }

    private static function auxiliaryObjectClassOptions(): array
    {
        return [
            'petraPerson' => 'petraPerson — AUXILIARY',
            'sambaSamAccount' => 'sambaSamAccount — AUXILIARY',
            'posixAccount' => 'posixAccount — AUXILIARY',
            'shadowAccount' => 'shadowAccount — AUXILIARY',
            'dcObject' => 'dcObject — AUXILIARY',
            'extensibleObject' => 'extensibleObject — AUXILIARY',
        ];
    }

    private static function mustAttributesForStructural(string $objectClass): array
    {
        return match ($objectClass) {
            'inetOrgPerson' => [
                'cn' => '',
                'sn' => '',
                'uid' => '',
                'mail' => '',
            ],
            'organizationalPerson', 'person' => [
                'cn' => '',
                'sn' => '',
            ],
            'account' => [
                'uid' => '',
            ],
            'organizationalUnit' => [
                'ou' => '',
            ],
            'groupOfNames' => [
                'cn' => '',
                'member' => '',
            ],
            'groupOfUniqueNames' => [
                'cn' => '',
                'uniqueMember' => '',
            ],
            default => [],
        };
    }

    private static function normalizeCreatePayload(array $data): array
    {
        $data['attributes'] = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        $rdnAttribute = trim((string) ($data['rdn_attribute'] ?? 'uid'));
        $rdnValue = trim((string) ($data['rdn_value'] ?? ''));

        if ($rdnAttribute !== '' && $rdnValue !== '') {
            $data['attributes'][$rdnAttribute] = $rdnValue;
        }

        if (($data['structural_object_class'] ?? null) === 'inetOrgPerson') {
            $data['attributes']['cn'] = trim((string) ($data['attributes']['cn'] ?? $rdnValue));
            $data['attributes']['sn'] = trim((string) ($data['attributes']['sn'] ?? $rdnValue));
            $data['attributes']['uid'] = trim((string) ($data['attributes']['uid'] ?? $rdnValue));

            if (! isset($data['attributes']['mail']) || trim((string) $data['attributes']['mail']) === '') {
                $data['attributes']['mail'] = str_contains($rdnValue, '@') ? $rdnValue : $rdnValue.'@petra.ac.id';
            }
        }

        return $data;
    }

    private static function safePayloadForLog(array $data): array
    {
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $key => $value) {
                if (in_array(strtolower((string) $key), ['userpassword', 'authpassword', 'unicodepwd'], true)) {
                    $data['attributes'][$key] = '[REDACTED]';
                }
            }
        }

        return $data;
    }
}
