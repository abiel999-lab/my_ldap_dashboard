<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;
use App\Jobs\Directory\ModifyLdapSchemaDefinitionJob;
use App\Jobs\Directory\SyncLdapSchemaEntriesJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapSchemaEntry;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';

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
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'attribute_type' => 'success',
                        'object_class' => 'info',
                        'ldap_syntax' => 'warning',
                        'matching_rule' => 'primary',
                        'matching_rule_use' => 'gray',
                        default => 'gray',
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('primary_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('oid')
                    ->label('OID')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('superior')
                    ->label('Superior')
                    ->default('N/A')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_single_value')
                    ->label('Single')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_operational')
                    ->label('Operational')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_obsolete')
                    ->label('Obsolete')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ldap_connection_id')
                    ->label('Connection')
                    ->state(fn ($record): string => static::connectionName($record->ldap_connection_id ?? null))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
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
                    ->options(static::schemaTypeOptions()),

                Tables\Filters\SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->options(fn (): array => static::connectionOptions()),

                Tables\Filters\SelectFilter::make('kind')
                    ->label('Kind')
                    ->options([
                        'user_attribute' => 'User Attribute',
                        'operational_attribute' => 'Operational Attribute',
                        'structural' => 'Structural ObjectClass',
                        'auxiliary' => 'Auxiliary ObjectClass',
                        'abstract' => 'Abstract ObjectClass',
                        'syntax' => 'LDAP Syntax',
                        'matching_rule' => 'Matching Rule',
                        'matching_rule_use' => 'Matching Rule Use',
                    ]),

                Tables\Filters\Filter::make('active_only')
                    ->label('Active only')
                    ->default(true)
                    ->query(fn ($query) => $query->where(function ($query): void {
                        $query->whereNull('status')->orWhere('status', 'active');
                    })),
            ])
            ->headerActions([
                Action::make('syncSchema')
                    ->label('Sync LDAP Schema')
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
                    ->action(fn (array $data) => static::queueSchemaSync($data)),

                Action::make('addSchema')
                    ->label('Add Schema')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form(static::schemaMutationForm())
                    ->action(fn (array $data) => static::queueSchemaMutation('add', $data)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('View'),

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
                        ->modalDescription('Schema definition akan dihapus lewat ldapmodify cn=config. Pastikan schema tidak sedang dipakai oleh entry LDAP.')
                        ->form(fn ($record): array => [
                            TextInput::make('schema_config_dn')
                                ->label('Schema Config DN')
                                ->default(static::defaultSchemaConfigDn($record))
                                ->required(),

                            Textarea::make('definition')
                                ->label('Definition to delete')
                                ->default((string) ($record->raw_definition ?? ''))
                                ->rows(8)
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueSchemaMutation('delete', $data, $record)),
                ])
                    ->label('LDAP Operations')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->button()
                    ->color('primary'),
            ])
            ->bulkActions([])
            ->recordUrl(fn ($record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
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
                ->required()
                ->helperText('attribute_type dan object_class adalah yang paling umum untuk custom schema. Syntax/matching rule biasanya built-in dan bisa ditolak LDAP jika tidak valid.'),

            TextInput::make('schema_config_dn')
                ->label('Schema Config DN')
                ->default(static::defaultSchemaConfigDn($record))
                ->placeholder('cn={99}petra,cn=schema,cn=config')
                ->required()
                ->helperText('DN schema di cn=config yang akan dimodifikasi.'),

            Textarea::make('definition')
                ->label('Schema Definition')
                ->default($record?->raw_definition)
                ->rows(10)
                ->required()
                ->helperText('Contoh attributeType: ( 1.3.6.1.4.1.99999.1.1 NAME \'petraExample\' DESC \'Example\' EQUALITY caseIgnoreMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.15 SINGLE-VALUE )'),

            Textarea::make('old_definition')
                ->label('Old Definition')
                ->default($record?->raw_definition)
                ->rows(6)
                ->visible(fn ($get): bool => filled($record))
                ->helperText('Dipakai saat replace/delete agar value lama bisa dihapus dengan presisi.'),
        ];
    }

    public static function queueSchemaSync(array $data): void
    {
        try {
            $connectionId = isset($data['ldap_connection_id']) && $data['ldap_connection_id'] !== ''
                ? (int) $data['ldap_connection_id']
                : null;

            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_schema_sync_queued',
                'queued job: SyncLdapSchemaEntriesJob',
                [
                    'operation' => 'sync_ldap_schema',
                    'ldap_connection_id' => $connectionId,
                    'scope' => $connectionId ? 'single_connection' : 'all_active_connections',
                    'queue' => 'ldap',
                ]
            );

            SyncLdapSchemaEntriesJob::dispatch($connectionId, SafeCommandExecutionLogger::id($execution));

            Notification::make()
                ->title('LDAP schema sync queued')
                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed('ldap_schema_sync_dispatch_failed', $e->getMessage(), $data);

            Notification::make()
                ->title('LDAP schema sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function queueSchemaMutation(string $operation, array $data, $record = null): void
    {
        try {
            $connectionId = (int) ($data['ldap_connection_id'] ?? $record?->ldap_connection_id ?? 0);

            if (! $connectionId) {
                throw new \RuntimeException('LDAP Connection is required.');
            }

            $schemaType = (string) ($data['schema_type'] ?? $record?->schema_type ?? 'attribute_type');
            $schemaConfigDn = trim((string) ($data['schema_config_dn'] ?? static::defaultSchemaConfigDn($record)));
            $definition = trim((string) ($data['definition'] ?? $record?->raw_definition ?? ''));
            $oldDefinition = trim((string) ($data['old_definition'] ?? $record?->raw_definition ?? ''));

            if ($schemaConfigDn === '' || $definition === '') {
                throw new \RuntimeException('Schema Config DN and Definition are required.');
            }

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

            ModifyLdapSchemaDefinitionJob::dispatch(
                $connectionId,
                $operation,
                $schemaType,
                $schemaConfigDn,
                $definition,
                $oldDefinition !== '' ? $oldDefinition : null,
                SafeCommandExecutionLogger::id($execution)
            );

            Notification::make()
                ->title('LDAP schema operation queued')
                ->body(strtoupper($operation).' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::createFailed('ldap_schema_mutation_dispatch_failed', $e->getMessage(), [
                'operation' => $operation,
                'data' => $data,
                'record_id' => $record?->id,
            ]);

            Notification::make()
                ->title('LDAP schema operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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

    public static function defaultSchemaConfigDn($record = null): string
    {
        if ($record && filled($record->schema_config_dn ?? null)) {
            return (string) $record->schema_config_dn;
        }

        return env('LDAP_SCHEMA_WRITE_DN', 'cn={99}petra,cn=schema,cn=config');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapSchemaEntries::route('/'),
            'view' => Pages\ViewLdapSchemaEntry::route('/{record}'),
        ];
    }
}
