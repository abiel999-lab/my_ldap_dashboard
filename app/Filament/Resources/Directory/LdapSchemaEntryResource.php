<?php

namespace App\Filament\Resources\Directory;

use App\Models\Operations\LdapSyncBatch;

use App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;
use App\Jobs\Directory\ModifyLdapSchemaDefinitionJob;
use App\Jobs\Directory\SyncLdapSchemaEntriesJob;
use App\Jobs\Directory\ExecuteSchemaBrowserSyncJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapSchemaEntry;
use App\Support\Directory\LdapSchemaDefinitionParser;
use App\Support\Operations\SafeCommandExecutionLogger;
use App\Services\Operations\OperationJobFactory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DbSchema;
use Throwable;

class LdapSchemaEntryResource extends Resource
{
    protected static ?string $model = LdapSchemaEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Schema Browser';

    protected static ?string $modelLabel = 'LDAP Schema Entry';

    protected static ?string $pluralModelLabel = 'LDAP Schema Browser';

    protected static string|\UnitEnum|null $navigationGroup = '1. DIRECTORY MANAGEMENT';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(LdapSchemaEntry::query())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('schema_type')
                    ->label('Type')
                    ->state(fn ($record): string => static::safeValue($record, ['schema_type', 'type'], 'unknown'))
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('primary_name')
                    ->label('Name')
                    ->state(fn ($record): string => static::safeValue($record, ['primary_name', 'name', 'display_name'], 'N/A'))
                    ->searchable(query: fn ($query, string $search) => $query->where(function ($query) use ($search): void {
                        foreach (['primary_name', 'name', 'display_name', 'oid', 'raw_definition', 'raw'] as $column) {
                            if (static::hasColumn($column)) {
                                $query->orWhere($column, 'ilike', '%'.$search.'%');
                            }
                        }
                    }))
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('oid')
                    ->label('OID')
                    ->state(fn ($record): string => static::safeValue($record, ['oid'], 'N/A'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Kind')
                    ->state(fn ($record): string => static::safeValue($record, ['kind'], 'N/A'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_single_value')
                    ->label('Single')
                    ->state(fn ($record): bool => (bool) ($record->is_single_value ?? false))
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_operational')
                    ->label('Operational')
                    ->state(fn ($record): bool => (bool) ($record->is_operational ?? false))
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_obsolete')
                    ->label('Obsolete')
                    ->state(fn ($record): bool => (bool) ($record->is_obsolete ?? false))
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ldap_connection_id')
                    ->label('Connection')
                    ->state(fn ($record): string => static::connectionName($record->ldap_connection_id ?? null))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(fn ($record): string => static::safeValue($record, ['status'], 'active'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime('M j, Y H:i:s')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('schema_type')
                    ->label('Schema Type')
                    ->options(static::schemaTypeOptions())
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;

                        if (! $value) {
                            return $query;
                        }

                        if (static::hasColumn('schema_type')) {
                            return $query->where('schema_type', $value);
                        }

                        if (static::hasColumn('type')) {
                            return $query->where('type', $value);
                        }

                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->options(fn (): array => static::connectionOptions()),

                Tables\Filters\Filter::make('active_only')
                    ->label('Active only')
                    ->default(true)
                    ->query(fn ($query) => static::hasColumn('status')
                        ? $query->where(function ($query): void {
                            $query->whereNull('status')->orWhere('status', 'active');
                        })
                        : $query
                    ),
            ])
            ->headerActions([
                Action::make('syncSchema')
                    ->label('Sync LDAP Schema')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->form([
                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => static::connectionOptions())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Checkbox::make('reset')
                            ->label('Reset schema cache for selected LDAP before sync')
                            ->default(true)
                            ->helperText('Disarankan aktif supaya schema tidak terlihat numpuk/duplikat.'),
                    ])
                    ->action(fn (array $data) => static::runDirectSchemaSync($data)),

                Action::make('addSchema')
                    ->label('Add Schema')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form(fn (): array => static::schemaMutationForm())
                    ->action(fn (array $data) => static::queueSchemaMutation('add', $data)),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn ($record): string => static::getUrl('view', ['record' => $record])),

                ActionGroup::make([
                    Action::make('replaceSchema')
                        ->label('Replace Schema')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->color('warning')
                        ->form(fn ($record): array => static::schemaMutationForm($record))
                        ->action(fn ($record, array $data) => static::queueSchemaMutation('replace', $data, $record)),

                    Action::make('deleteSchema')
                        ->label('Delete Schema')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete LDAP schema definition?')
                        ->modalDescription('Ini akan menghapus schema definition dari cn=config. Pastikan schema tidak sedang dipakai entry LDAP.')
                        ->form(fn ($record): array => static::schemaDeleteForm($record))
                        ->action(fn ($record, array $data) => static::queueSchemaMutation('delete', $data, $record)),
                ])
                    ->label('LDAP Operations')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->button()
                    ->color('primary'),
            ])
            ->bulkActions([])
            ->recordUrl(fn ($record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function schemaMutationForm($record = null): array
    {
        return [
            Select::make('ldap_connection_id')
                ->label('LDAP Connection')
                ->options(fn (): array => static::connectionOptions())
                ->default($record?->ldap_connection_id)
                ->searchable()
                ->preload()
                ->required(),

            Select::make('schema_type')
                ->label('Schema Type')
                ->options(static::schemaTypeOptions())
                ->default($record?->schema_type ?? 'attribute_type')
                ->required(),

            TextInput::make('schema_config_dn')
                ->label('Schema Config DN')
                ->placeholder('Optional. Leave empty to auto-resolve from LDAP Connection schema write config.')
                ->helperText('Opsional. Jika kosong, sistem mencari actual schema container DN dari LDAP Connection, misalnya cn={10}petra,cn=schema,cn=config.'),

            Textarea::make('raw_definition')
                ->label('Raw Definition')
                ->default($record?->raw_definition)
                ->rows(7)
                ->helperText('Kalau diisi, sistem memakai raw definition ini. Kalau kosong, sistem membuat definition dari builder field di bawah.'),

            TextInput::make('oid')
                ->label('OID')
                ->default($record?->oid)
                ->placeholder('1.3.6.1.4.1.99999.1.1'),

            TagsInput::make('names')
                ->label('Names')
                ->default(static::arrayValue($record?->names ?? []))
                ->placeholder('petraExample'),

            TextInput::make('description')
                ->label('Description')
                ->default($record?->description),

            Select::make('object_class_kind')
                ->label('ObjectClass Kind')
                ->options([
                    'STRUCTURAL' => 'STRUCTURAL',
                    'AUXILIARY' => 'AUXILIARY',
                    'ABSTRACT' => 'ABSTRACT',
                ])
                ->default(strtoupper((string) ($record?->kind ?: 'STRUCTURAL')))
                ->helperText('Dipakai jika Schema Type = object_class.'),

            TextInput::make('superior')
                ->label('SUP / Superior')
                ->default($record?->superior),

            TagsInput::make('must_attributes')
                ->label('MUST Attributes')
                ->default(static::arrayValue($record?->must_attributes ?? []))
                ->placeholder('cn'),

            TagsInput::make('may_attributes')
                ->label('MAY Attributes')
                ->default(static::arrayValue($record?->may_attributes ?? []))
                ->placeholder('description'),

            TextInput::make('syntax_oid')
                ->label('Syntax OID')
                ->default($record?->syntax_oid)
                ->placeholder('1.3.6.1.4.1.1466.115.121.1.15'),

            TextInput::make('equality_rule')
                ->label('Equality Rule')
                ->default($record?->equality_rule)
                ->placeholder('caseIgnoreMatch'),

            TextInput::make('ordering_rule')
                ->label('Ordering Rule')
                ->default($record?->ordering_rule),

            TextInput::make('substring_rule')
                ->label('Substring Rule')
                ->default($record?->substring_rule)
                ->placeholder('caseIgnoreSubstringsMatch'),

            TagsInput::make('applies_to_attributes')
                ->label('Applies To Attributes')
                ->default(static::arrayValue($record?->applies_to_attributes ?? []))
                ->placeholder('cn')
                ->helperText('Dipakai jika Schema Type = matching_rule_use.'),

            Toggle::make('is_single_value')
                ->label('Single Value')
                ->default((bool) ($record?->is_single_value ?? false)),

            Toggle::make('is_operational')
                ->label('Operational / No User Modification')
                ->default((bool) ($record?->is_operational ?? false)),

            Toggle::make('is_obsolete')
                ->label('Obsolete')
                ->default((bool) ($record?->is_obsolete ?? false)),

            Textarea::make('old_definition')
                ->label('Old Definition')
                ->default($record?->raw_definition)
                ->rows(5)
                ->visible(fn () => filled($record))
                ->helperText('Dipakai untuk replace agar sistem bisa menghapus value lama dengan tepat.'),
        ];
    }

    public static function schemaDeleteForm($record): array
    {
        return [
            TextInput::make('schema_config_dn')
                ->label('Schema Config DN')
                ->default(fn () => static::defaultSchemaConfigDn())
                ->required(),

            Textarea::make('raw_definition')
                ->label('Definition to Delete')
                ->default($record?->raw_definition)
                ->rows(8)
                ->required(),
        ];
    }

    public static function queueSchemaMutation(string $operation, array $data, $record = null): void
    {
        try {
            $connectionId = (int) ($data['ldap_connection_id'] ?? $record?->ldap_connection_id ?? 0);
            $schemaType = (string) ($data['schema_type'] ?? $record?->schema_type ?? 'attribute_type');
            $schemaConfigDn = trim((string) ($data['schema_config_dn'] ?? static::defaultSchemaConfigDn()));
            $definition = static::definitionFromForm($schemaType, $data, $record);
            $oldDefinition = trim((string) ($data['old_definition'] ?? $record?->raw_definition ?? ''));

            if (! $connectionId) {
                throw new \RuntimeException('LDAP Connection wajib dipilih.');
            }

            // Schema Config DN boleh kosong untuk schema write method yang support auto-resolve,
            // terutama kubernetes_ldapi_external. Executor akan mencari actual DN dari
            // LDAP Connection: schema_config_base_dn + schema_container_name.
            if ($definition === '') {
                throw new \RuntimeException('Schema definition kosong. Isi Raw Definition atau builder field.');
            }

            $executionId = null;

            try {
                if (method_exists(SafeCommandExecutionLogger::class, 'createQueued')) {
                    $execution = SafeCommandExecutionLogger::createQueued(
                        'ldap_schema_mutation_queued',
                        'queued job: ModifyLdapSchemaDefinitionJob',
                        [
                            'operation' => $operation,
                            'ldap_connection_id' => $connectionId,
                            'schema_type' => $schemaType,
                            'schema_config_dn' => $schemaConfigDn,
                            'definition' => $definition,
                            'old_definition' => $oldDefinition,
                            'queue' => 'ldap',
                        ]
                    );

                    $executionId = method_exists(SafeCommandExecutionLogger::class, 'id')
                        ? SafeCommandExecutionLogger::id($execution)
                        : null;
                }
            } catch (Throwable) {
                $executionId = null;
            }

            ModifyLdapSchemaDefinitionJob::dispatch(
                $connectionId,
                $operation,
                $schemaType,
                $schemaConfigDn,
                $definition,
                $oldDefinition !== '' ? $oldDefinition : null,
                $executionId
            );

            Notification::make()
                ->title('LDAP schema operation queued')
                ->body(strtoupper($operation).' schema queued'.($executionId ? ' | Command Execution ID: '.$executionId : ''))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('LDAP schema operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function runDirectSchemaSync(array $data = []): void
    {
        try {
            $ldapConnectionId = (int) ($data['ldap_connection_id'] ?? 0);
            $reset = (bool) ($data['reset_schema_cache'] ?? $data['reset_before_sync'] ?? true);

            if ($ldapConnectionId <= 0) {
                Notification::make()
                    ->title('LDAP schema sync failed')
                    ->body('Please select an LDAP connection.')
                    ->danger()
                    ->send();

                return;
            }

            $connection = LdapConnection::query()->find($ldapConnectionId);

            if (! $connection) {
                Notification::make()
                    ->title('LDAP schema sync failed')
                    ->body('Selected LDAP connection was not found.')
                    ->danger()
                    ->send();

                return;
            }

            $schemaBatch = LdapSyncBatch::query()->create([
                'name' => 'Schema Browser Full Sync - '.$connection->name,
                'ldap_connection_id' => $connection->id,
                'status' => 'queued',
                'sync_scope' => 'custom_dn',
                'base_dn' => (string) $connection->base_dn,
                'custom_target_dn' => 'cn=Subschema',
                'search_scope' => 'base',
                'filter' => '(objectClass=*)',
                'attributes' => 'objectClasses attributeTypes matchingRules ldapSyntaxes',
                'size_limit' => 1,
                'page_size' => 1,
                'safe_mode' => true,
                'preview_mode' => false,
                'destructive' => false,
                'message' => 'Schema browser full sync queued.',
                'metadata' => [
                    'source_page' => 'schema_browser.full',
                    'schema_sync' => true,
                    'reset_before_sync' => $reset,
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            $operationJob = app(OperationJobFactory::class)->createQueued([
                'operation_type' => 'ldap_schema_sync',
                'operation_action' => 'sync_schema_browser',
                'module' => 'directory.schema_browser',
                'title' => 'Schema Browser Sync - '.$connection->name,
                'queue_name' => 'ldap-schema',
                'source' => 'filament',
                'target_type' => LdapConnection::class,
                'target_key' => (string) $connection->id,
                'target_dn' => $connection->base_dn,
                'ldap_connection_id' => $connection->id,
                'total_items' => 1,
                'pending_items' => 1,
                'payload' => [
                    'ldap_connection_id' => $connection->id,
                    'ldap_sync_batch_id' => $schemaBatch->id,
                    'reset_before_sync' => $reset,
                    'source_page' => 'schema_browser',
                ],
                'metadata' => [
                    'ldap_connection_id' => $connection->id,
                    'ldap_sync_batch_id' => $schemaBatch->id,
                    'reset_before_sync' => $reset,
                    'source_page' => 'schema_browser',
                    'safe_mode' => true,
                    'destructive' => false,
                ],
            ]);

            $schemaBatch->forceFill([
                'operation_job_id' => $operationJob->id,
            ])->save();

            ExecuteSchemaBrowserSyncJob::dispatch(
                $operationJob->id,
                $connection->id,
                $reset,
                $schemaBatch->id,
            );

            Notification::make()
                ->title('LDAP schema sync queued')
                ->body('Operation Job ID: '.$operationJob->id)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('LDAP schema sync queue failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function definitionFromForm(string $schemaType, array $data, $record = null): string
    {
        $raw = trim((string) ($data['raw_definition'] ?? ''));

        if ($raw !== '') {
            return $raw;
        }

        $oid = trim((string) ($data['oid'] ?? $record?->oid ?? ''));
        $names = static::arrayValue($data['names'] ?? $record?->names ?? []);
        $description = trim((string) ($data['description'] ?? $record?->description ?? ''));
        $superior = trim((string) ($data['superior'] ?? $record?->superior ?? ''));
        $syntaxOid = trim((string) ($data['syntax_oid'] ?? $record?->syntax_oid ?? ''));
        $equalityRule = trim((string) ($data['equality_rule'] ?? $record?->equality_rule ?? ''));
        $orderingRule = trim((string) ($data['ordering_rule'] ?? $record?->ordering_rule ?? ''));
        $substringRule = trim((string) ($data['substring_rule'] ?? $record?->substring_rule ?? ''));
        $must = static::arrayValue($data['must_attributes'] ?? $record?->must_attributes ?? []);
        $may = static::arrayValue($data['may_attributes'] ?? $record?->may_attributes ?? []);
        $applies = static::arrayValue($data['applies_to_attributes'] ?? $record?->applies_to_attributes ?? []);
        $isSingleValue = (bool) ($data['is_single_value'] ?? $record?->is_single_value ?? false);
        $isOperational = (bool) ($data['is_operational'] ?? $record?->is_operational ?? false);
        $isObsolete = (bool) ($data['is_obsolete'] ?? $record?->is_obsolete ?? false);

        if ($oid === '') {
            throw new \RuntimeException('OID wajib diisi jika Raw Definition kosong.');
        }

        $parts = ['(', $oid];

        if ($names !== []) {
            $parts[] = "NAME ".static::nameDefinition($names);
        }

        if ($description !== '') {
            $parts[] = "DESC '".str_replace("'", "\\'", $description)."'";
        }

        if ($isObsolete) {
            $parts[] = 'OBSOLETE';
        }

        if ($superior !== '') {
            $parts[] = 'SUP '.$superior;
        }

        if ($schemaType === 'attribute_type') {
            if ($equalityRule !== '') {
                $parts[] = 'EQUALITY '.$equalityRule;
            }

            if ($orderingRule !== '') {
                $parts[] = 'ORDERING '.$orderingRule;
            }

            if ($substringRule !== '') {
                $parts[] = 'SUBSTR '.$substringRule;
            }

            if ($syntaxOid !== '') {
                $parts[] = 'SYNTAX '.$syntaxOid;
            }

            if ($isSingleValue) {
                $parts[] = 'SINGLE-VALUE';
            }

            if ($isOperational) {
                $parts[] = 'NO-USER-MODIFICATION';
                $parts[] = 'USAGE directoryOperation';
            }
        } elseif ($schemaType === 'object_class') {
            $kind = strtoupper((string) ($data['object_class_kind'] ?? $record?->kind ?? 'STRUCTURAL'));
            $kind = in_array($kind, ['STRUCTURAL', 'AUXILIARY', 'ABSTRACT'], true) ? $kind : 'STRUCTURAL';

            $parts[] = $kind;

            if ($must !== []) {
                $parts[] = 'MUST '.static::attributeListDefinition($must);
            }

            if ($may !== []) {
                $parts[] = 'MAY '.static::attributeListDefinition($may);
            }
        } elseif ($schemaType === 'ldap_syntax') {
            // OID + DESC is enough for custom syntax definition if LDAP allows it.
        } elseif ($schemaType === 'matching_rule') {
            if ($syntaxOid === '') {
                throw new \RuntimeException('Syntax OID wajib untuk matching_rule.');
            }

            $parts[] = 'SYNTAX '.$syntaxOid;
        } elseif ($schemaType === 'matching_rule_use') {
            if ($applies === []) {
                throw new \RuntimeException('Applies To Attributes wajib untuk matching_rule_use.');
            }

            $parts[] = 'APPLIES '.static::attributeListDefinition($applies);
        }

        $parts[] = ')';

        return implode(' ', $parts);
    }

    public static function nameDefinition(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));

        if (count($names) === 1) {
            return "'".$names[0]."'";
        }

        return "( ".implode(' ', array_map(fn ($name): string => "'".$name."'", $names))." )";
    }

    public static function attributeListDefinition(array $attributes): string
    {
        $attributes = array_values(array_filter(array_map('trim', $attributes)));

        if (count($attributes) === 1) {
            return $attributes[0];
        }

        return '( '.implode(' $ ', $attributes).' )';
    }

    public static function schemaTypeOptions(): array
    {
        return [
            'attribute_type' => 'Attribute Type',
            'object_class' => 'ObjectClass',
            'ldap_syntax' => 'LDAP Syntax',
            'matching_rule' => 'Matching Rule',
            'matching_rule_use' => 'Matching Rule Use',
        ];
    }

    public static function connectionOptions(): array
    {
        try {
            return LdapConnection::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
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

    public static function defaultSchemaConfigDn(): string
    {
        return env('LDAP_SCHEMA_WRITE_DN', '');
    }

    public static function arrayValue($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter(array_map('strval', $decoded)));
            }

            if ($value !== '') {
                return [$value];
            }
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        return [];
    }

    public static function safeValue($record, array $columns, string $default = 'N/A'): string
    {
        foreach ($columns as $column) {
            try {
                if (isset($record->{$column}) && $record->{$column} !== null && $record->{$column} !== '') {
                    return is_array($record->{$column})
                        ? json_encode($record->{$column}, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : (string) $record->{$column};
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $default;
    }

    public static function hasColumn(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            try {
                $columns = DbSchema::getColumnListing('ldap_schema_entries');
            } catch (Throwable) {
                $columns = [];
            }
        }

        return in_array($column, $columns, true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapSchemaEntries::route('/'),
            'view' => Pages\ViewLdapSchemaEntry::route('/{record}'),
        ];
    }
}
