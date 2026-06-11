<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\DirectoryObjectManagerResource\Pages;
use App\Jobs\Directory\BulkGenericLdapEntryMutationJob;
use App\Jobs\Directory\GenericLdapEntryMutationJob;
use App\Jobs\Directory\SyncDirectoryObjectsJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Support\Directory\LdapSchemaObjectClassHelper;
use App\Support\Operations\SafeCommandExecutionLogger;
use App\Services\Directory\DirectoryManagementSyncDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Throwable;

class DirectoryObjectManagerResource extends Resource
{
    protected static ?string $model = LdapDirectoryEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationLabel = 'Directory Object Manager';

    protected static ?string $modelLabel = 'Directory Object';

    protected static ?string $pluralModelLabel = 'Directory Object Manager';

    protected static string|\UnitEnum|null $navigationGroup = '1. DIRECTORY MANAGEMENT';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(LdapDirectoryEntry::query())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('dn')
                    ->label('DN')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(120),

                Tables\Columns\TextColumn::make('rdn')
                    ->label('RDN')
                    ->state(fn ($record): string => static::safeRdn((string) ($record->dn ?? '')))
                    ->searchable(query: fn ($query, string $search) => $query->where('dn', 'ilike', '%'.$search.'%'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('object_class_summary')
                    ->label('ObjectClass')
                    ->state(fn ($record): string => static::objectClassSummary($record))
                    ->badge()
                    ->wrap(),

                Tables\Columns\TextColumn::make('ldap_connection_id')
                    ->label('LDAP')
                    ->state(fn ($record): string => static::connectionName($record->ldap_connection_id ?? null))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->default('active')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime('M j, Y H:i:s')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->options(fn (): array => static::connectionOptions()),

                Tables\Filters\Filter::make('hide_deleted')
                    ->label('Hide deleted / missing')
                    ->default(true)
                    ->query(fn ($query) => $query->where(function ($query): void {
                        $query
                            ->whereNull('status')
                            ->orWhereNotIn('status', [
                                'missing_from_ldap',
                                'deleted_from_ldap',
                            ]);
                    })),

                Tables\Filters\Filter::make('only_ou')
                    ->label('Only OU')
                    ->query(fn ($query) => $query->where('dn', 'ilike', 'ou=%')),

                Tables\Filters\Filter::make('only_cn')
                    ->label('Only CN')
                    ->query(fn ($query) => $query->where('dn', 'ilike', 'cn=%')),

                Tables\Filters\Filter::make('only_uid')
                    ->label('Only UID')
                    ->query(fn ($query) => $query->where('dn', 'ilike', 'uid=%')),
            ])
            ->headerActions([
                Action::make('syncDirectoryObjects')
                    ->label('Sync Objects')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->form([
                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(['' => 'All active LDAP connections'] + static::connectionOptions())
                            ->default('')
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(fn (array $data) => static::queueSyncObjects($data)),

                Action::make('createObject')
                    ->label('Create LDAP Object')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form(static::createObjectForm())
                    ->action(fn (array $data) => static::queueCreateObject($data)),
            ])
            ->actions([
                Action::make('viewObjectText')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn ($record): string => static::recordTargetUrl($record)),

                Action::make('syncObjectText')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(fn ($record) => static::queueSyncSingleObject($record)),

                Action::make('deleteObjectText')
                    ->label('Delete LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP object?')
                    ->modalDescription('Object akan dihapus dari LDAP lewat queue dan tercatat di Command Executions.')
                    ->action(fn ($record) => static::queueMutation($record, 'delete_entry', [])),

                ActionGroup::make([

                    Action::make('syncThisObject')
                        ->label('Sync')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(fn ($record) => static::queueSyncSingleObject($record)),

                    Action::make('addObjectClass')
                        ->label('Add ObjectClass')
                        ->icon('heroicon-o-cube')
                        ->color('primary')
                        ->form([
                            Select::make('object_class')
                                ->label('ObjectClass')
                                ->options(fn ($record): array => static::auxiliaryOnlyOptions((int) ($record->ldap_connection_id ?? 0)))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(fn ($state, callable $set, $record) => $set('must_attributes', static::mustAttributeRowsForObjectClass((int) ($record->ldap_connection_id ?? 0), (string) $state)))
                                ->required(),

                            Repeater::make('must_attributes')
                                ->label('MUST Attributes')
                                ->schema([
                                    TextInput::make('attribute')
                                        ->label('Attribute')
                                        ->disabled()
                                        ->dehydrated()
                                        ->required(),

                                    TextInput::make('value')
                                        ->label('Value')
                                        ->required(),
                                ])
                                ->columns(2)
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->helperText('Nama attribute otomatis. User hanya isi value. Direct add hanya untuk AUXILIARY objectClass.'),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'add_objectclass', $data)),

                    Action::make('removeObjectClass')
                        ->label('Remove ObjectClass')
                        ->icon('heroicon-o-cube-transparent')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Select::make('object_class')
                                ->label('ObjectClass')
                                ->options(fn ($record): array => static::removableAuxiliaryObjectClassOptions($record))
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'remove_objectclass', array_merge($data, ['auto_remove_related_attributes' => true]))),

                    Action::make('renameRdn')
                        ->label('Rename RDN')
                        ->icon('heroicon-o-pencil-square')
                        ->color('info')
                        ->form([
                            TextInput::make('rdn_attribute')
                                ->label('RDN Attribute')
                                ->default(fn ($record): string => static::rdnAttribute((string) ($record->dn ?? '')))
                                ->required(),

                            TextInput::make('rdn_value')
                                ->label('New RDN Value')
                                ->required(),

                            Toggle::make('delete_old_rdn')
                                ->label('Delete old RDN value')
                                ->default(true),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'rename_rdn', $data)),

                    Action::make('moveEntry')
                        ->label('Move Parent DN')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->form([
                            TextInput::make('new_parent_dn')
                                ->label('New Parent DN')
                                ->default(fn ($record): string => static::parentDn((string) ($record->dn ?? '')))
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'move_entry', $data)),
                ])
                    ->label('LDAP Operations')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->button()
                    ->color('primary'),
            ])
            ->recordUrl(fn ($record): string => static::recordTargetUrl($record))
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkDeleteLdap')
                        ->label('Delete Selected From LDAP')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected LDAP objects?')
                        ->modalDescription('Semua object terpilih akan dihapus lewat queue. OU yang masih punya child kemungkinan ditolak LDAP.')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn ($records) => static::queueBulkMutation($records, 'delete_entry', [])),

                    BulkAction::make('bulkMoveDn')
                        ->label('Move Selected DN')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->form([
                            TextInput::make('new_parent_dn')
                                ->label('New Parent DN')
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn ($records, array $data) => static::queueBulkMutation($records, 'move_entry', $data)),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public static function createObjectForm(): array
    {
        return [
            Select::make('ldap_connection_id')
                ->label('LDAP Connection')
                ->options(fn (): array => static::connectionOptions())
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('parent_dn')
                ->label('Parent DN')
                ->placeholder('dc=petra,dc=ac,dc=id')
                ->required(),

            TextInput::make('rdn_attribute')
                ->label('RDN Attribute')
                ->placeholder('ou / cn / uid')
                ->default('cn')
                ->required(),

            TextInput::make('rdn_value')
                ->label('RDN Value')
                ->placeholder('example-object')
                ->required(),

            Select::make('structural_object_class')
                ->label('Structural ObjectClass')
                ->options(fn (): array => static::safeStructuralObjectClassOptions())
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Pilih structural objectClass.'),

            Select::make('auxiliary_object_classes')
                ->label('Auxiliary ObjectClasses')
                ->multiple()
                ->options(fn (): array => static::safeAuxiliaryObjectClassOptions())
                ->searchable()
                ->preload()
                ->helperText('Opsional. Pilih auxiliary objectClass tambahan.'),

            KeyValue::make('attributes')
                ->label('LDAP Attributes')
                ->keyLabel('Attribute')
                ->valueLabel('Value')
                ->helperText('Jangan isi objectClass di sini. RDN attribute akan otomatis ditambahkan dan dedupe. Multi-value pisahkan dengan koma.'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDirectoryObjects::route('/'),
            'view' => Pages\ViewDirectoryObject::route('/{record}'),
        ];
    }

    public static function auxiliaryOnlyOptions(?int $connectionId = null): array
    {
        try {
            $options = app(LdapSchemaObjectClassHelper::class)->auxiliaryOptions($connectionId);

            if ($options !== []) {
                return $options;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'extensibleObject' => 'extensibleObject — AUXILIARY',
            'simpleSecurityObject' => 'simpleSecurityObject — AUXILIARY — MUST: userPassword',
        ];
    }

    public static function removableAuxiliaryObjectClassOptions($record): array
    {
        $currentClasses = collect(static::extractObjectClasses($record))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($currentClasses->isEmpty()) {
            return [];
        }

        try {
            $auxiliaryNames = collect(app(LdapSchemaObjectClassHelper::class)->auxiliaryOptions((int) ($record->ldap_connection_id ?? 0)))
                ->keys()
                ->map(fn ($value): string => strtolower((string) $value))
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            $auxiliaryNames = [
                'extensibleobject',
                'simplesecurityobject',
                'petr_person',
                'petraperson',
                'sambasamaccount',
            ];
        }

        return $currentClasses
            ->reject(fn (string $class): bool => strtolower($class) === 'top')
            ->filter(fn (string $class): bool => in_array(strtolower($class), $auxiliaryNames, true))
            ->mapWithKeys(fn (string $class): array => [$class => $class.' — AUXILIARY'])
            ->toArray();
    }


    public static function mustAttributeRowsForObjectClass(?int $connectionId, string $objectClass): array
    {
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [];
        }

        $label = static::auxiliaryOnlyOptions($connectionId)[$objectClass] ?? '';

        $must = static::parseMustAttributesFromObjectClassLabel($label);

        return collect($must)
            ->map(fn (string $attribute): array => [
                'attribute' => $attribute,
                'value' => '',
            ])
            ->values()
            ->all();
    }

    public static function parseMustAttributesFromObjectClassLabel(string $label): array
    {
        if ($label === '') {
            return [];
        }

        if (! preg_match('/MUST:\s*(.*?)\s*(?:—|$)/u', $label, $matches)) {
            return [];
        }

        $raw = trim((string) ($matches[1] ?? ''));

        if ($raw === '' || strtolower($raw) === 'none') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeObjectClassMutationPayload($record, string $operation, array $payload): array
    {
        if ($operation === 'add_objectclass') {
            $rows = $payload['must_attributes'] ?? [];

            if (is_array($rows)) {
                $normalized = [];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $attribute = trim((string) ($row['attribute'] ?? ''));
                    $value = trim((string) ($row['value'] ?? ''));

                    if ($attribute === '' || $value === '') {
                        continue;
                    }

                    $normalized[$attribute] = $value;
                }

                $payload['must_attributes'] = $normalized;
            }
        }

        if ($operation === 'remove_objectclass') {
            unset($payload['remove_attributes']);

            $payload['auto_remove_related_attributes'] = true;
            $payload['remove_related_attributes_mode'] = 'auto';
        }

        return $payload;
    }


    public static function safeStructuralObjectClassOptions(): array
    {
        try {
            $options = app(LdapSchemaObjectClassHelper::class)->structuralOptions(null);

            if ($options !== []) {
                return $options;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'organizationalUnit' => 'organizationalUnit — STRUCTURAL — MUST: ou',
            'groupOfNames' => 'groupOfNames — STRUCTURAL — MUST: cn, member',
            'groupOfUniqueNames' => 'groupOfUniqueNames — STRUCTURAL — MUST: cn, uniqueMember',
            'organizationalRole' => 'organizationalRole — STRUCTURAL — MUST: cn',
            'applicationProcess' => 'applicationProcess — STRUCTURAL — MUST: cn',
            'device' => 'device — STRUCTURAL — MUST: cn',
            'inetOrgPerson' => 'inetOrgPerson — STRUCTURAL — MUST: cn, sn',
        ];
    }

    public static function safeAuxiliaryObjectClassOptions(): array
    {
        try {
            $options = app(LdapSchemaObjectClassHelper::class)->auxiliaryOptions(null);

            if ($options !== []) {
                return $options;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'extensibleObject' => 'extensibleObject — AUXILIARY',
            'simpleSecurityObject' => 'simpleSecurityObject — AUXILIARY — MUST: userPassword',
        ];
    }

    public static function queueSyncObjects(array $data): void
    {
        try {
            $connectionId = isset($data['ldap_connection_id']) && $data['ldap_connection_id'] !== ''
                ? (int) $data['ldap_connection_id']
                : null;

            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_objects_sync_queued',
                'queued job: SyncDirectoryObjectsJob',
                [
                    'operation' => 'sync_directory_objects',
                    'ldap_connection_id' => $connectionId,
                    'queue' => 'ldap',
                    'source' => 'directory_object_manager',
                ]
            );

            SyncDirectoryObjectsJob::dispatch(
                $connectionId,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('Directory objects sync queued')
                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A').' | Queue: ldap')
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_objects_sync_dispatch_failed',
                $e->getMessage(),
                [
                    'operation' => 'sync_directory_objects',
                    'data' => $data,
                ]
            );

            Notification::make()
                ->title('Directory objects sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function queueSyncSingleObject($record): void
    {
        try {
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_single_sync_queued',
                'queued job: SyncDirectoryObjectsJob',
                [
                    'operation' => 'sync_directory_object_connection',
                    'ldap_connection_id' => $record->ldap_connection_id ?? null,
                    'dn' => $record->dn ?? null,
                    'queue' => 'ldap',
                ]
            );

            SyncDirectoryObjectsJob::dispatch(
                $record->ldap_connection_id ? (int) $record->ldap_connection_id : null,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('Directory object sync queued')
                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_single_sync_dispatch_failed',
                $e->getMessage(),
                [
                    'record_id' => $record->id ?? null,
                    'dn' => $record->dn ?? null,
                ]
            );

            Notification::make()
                ->title('Sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function queueCreateObject(array $data): void
    {
        try {
            $parentDn = trim((string) ($data['parent_dn'] ?? ''));
            $rdnAttribute = trim((string) ($data['rdn_attribute'] ?? 'cn'));
            $rdnValue = trim((string) ($data['rdn_value'] ?? ''));

            if ($parentDn === '' || $rdnAttribute === '' || $rdnValue === '') {
                throw new \RuntimeException('Parent DN, RDN Attribute, and RDN Value are required.');
            }

            $structural = trim((string) ($data['structural_object_class'] ?? ''));

            if ($structural === '') {
                throw new \RuntimeException('Structural objectClass is required.');
            }

            $dn = $rdnAttribute.'='.$rdnValue.','.$parentDn;
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

            foreach (array_keys($attributes) as $attributeKey) {
                if (strtolower((string) $attributeKey) === 'objectclass') {
                    unset($attributes[$attributeKey]);
                }
            }

            $objectClasses = collect(array_merge(
                ['top'],
                [$structural],
                (array) ($data['auxiliary_object_classes'] ?? [])
            ))
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $attributes[$rdnAttribute] = $rdnValue;

            foreach (static::commonMustAttributes($structural, $objectClasses) as $mustAttribute) {
                if (! array_key_exists($mustAttribute, $attributes) || trim((string) $attributes[$mustAttribute]) === '') {
                    if (strtolower($mustAttribute) === strtolower($rdnAttribute)) {
                        $attributes[$mustAttribute] = $rdnValue;
                    } elseif ($mustAttribute === 'cn') {
                        $attributes[$mustAttribute] = $rdnValue;
                    } elseif ($mustAttribute === 'ou') {
                        $attributes[$mustAttribute] = $rdnValue;
                    }
                }
            }

            $payload = [
                'ldap_connection_id' => (int) $data['ldap_connection_id'],
                'dn' => $dn,
                'object_classes' => $objectClasses,
                'attributes' => static::dedupeAttributes($attributes),
            ];

            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_create_queued',
                'queued job: GenericLdapEntryMutationJob',
                [
                    'operation' => 'create_entry',
                    'payload' => $payload,
                    'queue' => 'ldap',
                ]
            );

            GenericLdapEntryMutationJob::dispatch(
                LdapDirectoryEntry::class,
                null,
                'create_entry',
                $payload,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('LDAP object create queued')
                ->body('DN: '.$dn.' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed('ldap_directory_object_create_dispatch_failed', $e->getMessage(), $data);

            Notification::make()
                ->title('LDAP object create failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function queueMutation($record, string $operation, array $payload = []): void
    {
        try {
            $payload = static::normalizeObjectClassMutationPayload($record, $operation, $payload);
            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_mutation_queued',
                'queued job: GenericLdapEntryMutationJob',
                [
                    'operation' => $operation,
                    'model_class' => get_class($record),
                    'record_id' => $record->id ?? null,
                    'dn' => $record->dn ?? null,
                    'payload' => $payload,
                    'queue' => 'ldap',
                ]
            );

            GenericLdapEntryMutationJob::dispatch(
                get_class($record),
                (int) $record->id,
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
                'ldap_directory_object_mutation_dispatch_failed',
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'record_id' => $record->id ?? null,
                    'dn' => $record->dn ?? null,
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

    public static function queueBulkMutation($records, string $operation, array $payload = []): void
    {
        try {
            $ids = $records->pluck('id')->values()->all();
            $first = $records->first();

            if (! $first) {
                throw new \RuntimeException('No selected records.');
            }

            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_directory_object_bulk_mutation_queued',
                'queued job: BulkGenericLdapEntryMutationJob',
                [
                    'operation' => 'bulk_'.$operation,
                    'model_class' => get_class($first),
                    'record_count' => count($ids),
                    'record_ids' => $ids,
                    'payload' => $payload,
                    'queue' => 'ldap',
                ]
            );

            BulkGenericLdapEntryMutationJob::dispatch(
                get_class($first),
                $ids,
                $operation,
                $payload,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('Bulk LDAP operation queued')
                ->body('Total: '.count($ids).' | Operation: '.$operation.' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_bulk_mutation_dispatch_failed',
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'payload' => $payload,
                ]
            );

            Notification::make()
                ->title('Bulk LDAP operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function commonMustAttributes(string $structural, array $objectClasses = []): array
    {
        $map = [
            'organizationalUnit' => ['ou'],
            'groupOfNames' => ['cn', 'member'],
            'groupOfUniqueNames' => ['cn', 'uniqueMember'],
            'organizationalRole' => ['cn'],
            'applicationProcess' => ['cn'],
            'device' => ['cn'],
            'inetOrgPerson' => ['cn', 'sn'],
            'simpleSecurityObject' => ['userPassword'],
        ];

        $must = $map[$structural] ?? [];

        foreach ($objectClasses as $objectClass) {
            foreach (($map[trim((string) $objectClass)] ?? []) as $attribute) {
                $must[] = $attribute;
            }
        }

        return collect($must)->map(fn ($value): string => trim((string) $value))->filter()->unique()->values()->all();
    }

    public static function dedupeAttributes(array $attributes): array
    {
        $clean = [];

        foreach ($attributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '' || strtolower($attribute) === 'objectclass') {
                continue;
            }

            $values = is_array($value) ? $value : explode(',', (string) $value);

            $values = collect($values)
                ->map(fn ($item): string => trim((string) $item))
                ->filter(fn (string $item): bool => $item !== '')
                ->unique()
                ->values()
                ->all();

            if ($values === []) {
                continue;
            }

            $clean[$attribute] = count($values) === 1 ? $values[0] : $values;
        }

        return $clean;
    }

    public static function connectionOptions(): array
    {
        try {
            return LdapConnection::query()->orderBy('name')->pluck('name', 'id')->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    public static function connectionName($id): string
    {
        if (! $id) {
            return 'N/A';
        }

        try {
            return (string) (LdapConnection::query()->whereKey($id)->value('name') ?: $id);
        } catch (Throwable) {
            return (string) $id;
        }
    }

    public static function objectClassSummary($record): string
    {
        $classes = static::extractObjectClasses($record);

        return $classes === [] ? 'unknown' : implode(', ', array_slice($classes, 0, 3));
    }

    public static function extractObjectClasses($record): array
    {
        $candidates = [
            $record->object_classes ?? null,
            $record->objectClass ?? null,
            $record->attributes ?? null,
            $record->raw_attributes ?? null,
            $record->normal_attributes ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    $value = $decoded;
                } else {
                    continue;
                }
            }

            if (is_array($value)) {
                $classes = $value['objectClass'] ?? $value['objectclass'] ?? null;

                if (is_array($classes)) {
                    return collect($classes)
                        ->map(fn ($v) => is_array($v) ? Arr::first($v) : $v)
                        ->map(fn ($v) => (string) $v)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }

                if (is_string($classes)) {
                    return [$classes];
                }

                if (array_is_list($value)) {
                    return collect($value)->map(fn ($v) => (string) $v)->filter()->unique()->values()->all();
                }
            }
        }

        return [];
    }

    public static function safeRdn(string $dn): string
    {
        return $dn === '' ? 'N/A' : (explode(',', $dn, 2)[0] ?? $dn);
    }

    public static function rdnAttribute(string $dn): string
    {
        $rdn = static::safeRdn($dn);

        return str_contains($rdn, '=') ? explode('=', $rdn, 2)[0] : 'cn';
    }

    public static function parentDn(string $dn): string
    {
        return str_contains($dn, ',') ? (explode(',', $dn, 2)[1] ?? '') : '';
    }

    public static function isUserObject($record): bool
    {
        $dn = strtolower((string) ($record->dn ?? ''));

        if (str_starts_with($dn, 'uid=')) {
            return true;
        }

        $classes = collect(static::extractObjectClasses($record))
            ->map(fn ($value): string => strtolower((string) $value))
            ->values()
            ->all();

        return in_array('inetorgperson', $classes, true) || str_contains($dn, ',ou=people,');
    }

    public static function userUrl($record): string
    {
        $id = static::findUserIdForDirectoryObject($record);

        return $id ? url('/admin/directory/ldap-user-entries/'.$id) : url('/admin/directory/ldap-user-entries');
    }

    public static function recordTargetUrl($record): string
    {
        if (static::isUserObject($record)) {
            return static::userUrl($record);
        }

        return static::getUrl('view', ['record' => $record]);
    }

    public static function findUserIdForDirectoryObject($record): ?int
    {
        try {
            if (! class_exists(\App\Models\Directory\LdapUserEntry::class)) {
                return null;
            }

            $query = \App\Models\Directory\LdapUserEntry::query()->where('dn', (string) ($record->dn ?? ''));

            if (! empty($record->ldap_connection_id)) {
                $query->where('ldap_connection_id', $record->ldap_connection_id);
            }

            $id = $query->value('id');

            if ($id) {
                return (int) $id;
            }

            $rdn = static::safeRdn((string) ($record->dn ?? ''));

            if (str_contains($rdn, '=')) {
                [$attribute, $value] = explode('=', $rdn, 2);

                if (strtolower($attribute) === 'uid') {
                    $query = \App\Models\Directory\LdapUserEntry::query()->where('uid', $value);

                    if (! empty($record->ldap_connection_id)) {
                        $query->where('ldap_connection_id', $record->ldap_connection_id);
                    }

                    $id = $query->value('id');

                    if ($id) {
                        return (int) $id;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    public static function normalAttributesForDetail($record): array
    {
        $attributes = static::firstArrayValueFromRecord($record, [
            'attributes',
            'normal_attributes',
            'raw_attributes',
        ]);

        foreach (static::operationalAttributeKeys() as $key) {
            unset($attributes[$key], $attributes[strtolower($key)]);
        }

        unset($attributes['objectClass'], $attributes['objectclass']);

        return $attributes;
    }

    public static function operationalAttributesForDetail($record): array
    {
        $all = static::firstArrayValueFromRecord($record, [
            'operational_attributes',
            'attributes',
            'raw_attributes',
            'normal_attributes',
        ]);

        $result = [];

        foreach (static::operationalAttributeKeys() as $key) {
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

    public static function operationalAttributeKeys(): array
    {
        return [
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
    }

    public static function firstArrayValueFromRecord($record, array $columns): array
    {
        foreach ($columns as $column) {
            if (! isset($record->{$column})) {
                continue;
            }

            $value = $record->{$column};

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

    public static function attributeRowsForDetail(array $attributes): array
    {
        ksort($attributes);

        $rows = [];

        foreach ($attributes as $attribute => $value) {
            if (strtolower((string) $attribute) === 'objectclass') {
                continue;
            }

            $values = is_array($value) ? $value : [$value];

            $values = collect($values)
                ->map(function ($item): string {
                    if (is_array($item)) {
                        return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    return (string) $item;
                })
                ->filter(fn (string $item): bool => trim($item) !== '')
                ->values()
                ->all();

            $rows[] = [
                'attribute' => (string) $attribute,
                'count' => count($values),
                'type' => count($values) > 1 ? 'Multi Value' : 'Single Value',
                'values_text' => $values === [] ? 'N/A' : implode("\n", array_map(fn ($v): string => '- '.$v, $values)),
            ];
        }

        return $rows;
    }
}
