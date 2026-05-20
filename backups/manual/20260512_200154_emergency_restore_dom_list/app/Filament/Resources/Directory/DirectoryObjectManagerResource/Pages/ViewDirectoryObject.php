<?php

namespace App\Filament\Resources\Directory\DirectoryObjectManagerResource\Pages;

use App\Filament\Resources\Directory\DirectoryObjectManagerResource;
use App\Jobs\Directory\GenericLdapEntryMutationJob;
use App\Jobs\Directory\SyncDirectoryObjectsJob;
use App\Support\Directory\LdapSchemaObjectClassHelper;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Throwable;

class ViewDirectoryObject extends ViewRecord
{
    protected static string $resource = DirectoryObjectManagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncThisObject')
                ->label('Sync This Object')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $this->queueSyncThisObject();
                }),

            ActionGroup::make([
                Action::make('renameRdn')
                    ->label('Rename RDN')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->form([
                        TextInput::make('rdn_attribute')
                            ->label('RDN Attribute')
                            ->default(fn (): string => DirectoryObjectManagerResource::rdnAttribute((string) ($this->record->dn ?? '')))
                            ->required(),

                        TextInput::make('rdn_value')
                            ->label('New RDN Value')
                            ->required(),

                        Toggle::make('delete_old_rdn')
                            ->label('Delete old RDN value')
                            ->default(true),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('rename_rdn', $data)),

                Action::make('moveEntry')
                    ->label('Move Parent DN')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->form([
                        TextInput::make('new_parent_dn')
                            ->label('New Parent DN')
                            ->default(fn (): string => $this->parentDn((string) ($this->record->dn ?? '')))
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('move_entry', $data)),

                Action::make('addObjectClass')
                    ->label('Add ObjectClass')
                    ->icon('heroicon-o-cube')
                    ->color('primary')
                    ->form([
                        Select::make('object_class')
                            ->label('ObjectClass')
                            ->options(fn (): array => app(LdapSchemaObjectClassHelper::class)
                                ->objectClassOptions((int) ($this->record->ldap_connection_id ?? 0)))
                            ->searchable()
                            ->preload()
                            ->required(),

                        KeyValue::make('must_attributes')
                            ->label('MUST Attributes')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->helperText('Isi jika objectClass membutuhkan MUST attribute tambahan.'),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('add_objectclass', $data)),

                Action::make('removeObjectClass')
                    ->label('Remove ObjectClass')
                    ->icon('heroicon-o-cube-transparent')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('object_class')
                            ->label('ObjectClass')
                            ->options(fn (): array => collect(DirectoryObjectManagerResource::extractObjectClasses($this->record))
                                ->mapWithKeys(fn ($value): array => [$value => $value])
                                ->toArray())
                            ->searchable()
                            ->preload()
                            ->required(),

                        TagsInput::make('remove_attributes')
                            ->label('Remove attributes first')
                            ->helperText('Jika objectClass masih dipakai attribute tertentu, masukkan attribute yang perlu dihapus dulu.'),
                    ])
                    ->action(fn (array $data) => $this->queueMutation('remove_objectclass', $data)),

                Action::make('deleteObject')
                    ->label('Delete Object')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP object?')
                    ->modalDescription('Object akan dihapus dari LDAP lewat queue dan tercatat di Command Executions.')
                    ->action(fn () => $this->queueMutation('delete_entry', [])),
            ])
                ->label('Object Actions')
                ->icon('heroicon-o-command-line')
                ->color('primary'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $attributes = $this->attributesForDisplay();
        $objectClasses = DirectoryObjectManagerResource::extractObjectClasses($this->record);
        $operational = $this->operationalAttributesForDisplay();

        return $schema->components([
            Tabs::make('DirectoryObjectTabs')
                ->tabs([
                    Tab::make('Overview')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Section::make('Identity')
                                        ->schema([
                                            Text::make('DN')
                                                ->content((string) ($this->record->dn ?? 'N/A')),
                                            Text::make('RDN')
                                                ->content(DirectoryObjectManagerResource::safeRdn((string) ($this->record->dn ?? ''))),
                                            Text::make('Parent DN')
                                                ->content($this->parentDn((string) ($this->record->dn ?? ''))),
                                            Text::make('LDAP Connection')
                                                ->content(DirectoryObjectManagerResource::connectionName($this->record->ldap_connection_id ?? null)),
                                        ]),

                                    Section::make('Summary')
                                        ->schema([
                                            Text::make('Status')
                                                ->content((string) ($this->record->status ?? 'active')),
                                            Text::make('ObjectClasses')
                                                ->content((string) count($objectClasses)),
                                            Text::make('Directory Attributes')
                                                ->content((string) count($attributes)),
                                            Text::make('Operational Attributes')
                                                ->content((string) count($operational)),
                                        ]),
                                ]),
                        ]),

                    Tab::make('ObjectClasses')
                        ->icon('heroicon-o-cube')
                        ->schema([
                            Section::make('ObjectClasses')
                                ->schema([
                                    Text::make('ObjectClass list')
                                        ->content($objectClasses === [] ? 'No objectClass found.' : implode("\n", array_map(fn ($v) => '- '.$v, $objectClasses))),
                                ]),
                        ]),

                    Tab::make('Attributes')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->schema([
                            Section::make('Directory Attributes')
                                ->description('Normal LDAP attributes stored on this object.')
                                ->schema([
                                    Text::make('Attributes')
                                        ->content($this->renderAttributes($attributes)),
                                ]),
                        ]),

                    Tab::make('Operational')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make('Operational / Read-only Attributes')
                                ->description('Server-managed attributes. Do not edit these manually.')
                                ->schema([
                                    Text::make('Operational Attributes')
                                        ->content($this->renderAttributes($operational)),
                                ]),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    private function queueMutation(string $operation, array $payload = []): void
    {
        try {
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_detail_mutation_queued',
                'queued job: GenericLdapEntryMutationJob',
                [
                    'operation' => $operation,
                    'model_class' => get_class($this->record),
                    'record_id' => $this->record->id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'payload' => $payload,
                    'queue' => 'ldap',
                ]
            );

            GenericLdapEntryMutationJob::dispatch(
                get_class($this->record),
                (int) $this->record->id,
                $operation,
                $payload,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('LDAP operation queued')
                ->body('Operation: '.$operation.' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_detail_mutation_failed',
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'record_id' => $this->record->id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'payload' => $payload,
                ]
            );

            Notification::make()
                ->title('LDAP operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function queueSyncThisObject(): void
    {
        try {
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_single_sync_queued',
                'queued job: SyncDirectoryObjectsJob',
                [
                    'operation' => 'sync_directory_object_connection',
                    'ldap_connection_id' => $this->record->ldap_connection_id ?? null,
                    'dn' => $this->record->dn ?? null,
                    'queue' => 'ldap',
                ]
            );

            SyncDirectoryObjectsJob::dispatch(
                $this->record->ldap_connection_id ? (int) $this->record->ldap_connection_id : null,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('Directory object sync queued')
                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function attributesForDisplay(): array
    {
        $attributes = $this->firstArrayFromRecord([
            'attributes',
            'normal_attributes',
            'raw_attributes',
        ]);

        $operationalKeys = [
            'entryUUID',
            'entryCSN',
            'creatorsName',
            'createTimestamp',
            'modifiersName',
            'modifyTimestamp',
            'structuralObjectClass',
            'subschemaSubentry',
            'hasSubordinates',
            'memberOf',
            'pwdChangedTime',
            'pwdAccountLockedTime',
        ];

        foreach ($operationalKeys as $key) {
            unset($attributes[$key], $attributes[strtolower($key)]);
        }

        return $attributes;
    }

    private function operationalAttributesForDisplay(): array
    {
        $all = $this->firstArrayFromRecord([
            'operational_attributes',
            'attributes',
            'raw_attributes',
            'normal_attributes',
        ]);

        $operationalKeys = [
            'entryUUID',
            'entryCSN',
            'creatorsName',
            'createTimestamp',
            'modifiersName',
            'modifyTimestamp',
            'structuralObjectClass',
            'subschemaSubentry',
            'hasSubordinates',
            'memberOf',
            'pwdChangedTime',
            'pwdAccountLockedTime',
        ];

        $result = [];

        foreach ($operationalKeys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }

            $lower = strtolower($key);

            if (array_key_exists($lower, $all)) {
                $result[$lower] = $all[$lower];
            }
        }

        return $result;
    }

    private function firstArrayFromRecord(array $columns): array
    {
        foreach ($columns as $column) {
            if (! isset($this->record->{$column})) {
                continue;
            }

            $value = $this->record->{$column};

            if (is_array($value)) {
                return $value;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private function renderAttributes(array $attributes): string
    {
        if ($attributes === []) {
            return 'No attributes found.';
        }

        $lines = [];

        foreach ($attributes as $attribute => $value) {
            $lines[] = $attribute.':';

            $values = is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                if (is_array($item)) {
                    $item = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $lines[] = '  - '.(string) $item;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function parentDn(string $dn): string
    {
        if (! str_contains($dn, ',')) {
            return 'N/A';
        }

        return explode(',', $dn, 2)[1] ?? 'N/A';
    }
}
