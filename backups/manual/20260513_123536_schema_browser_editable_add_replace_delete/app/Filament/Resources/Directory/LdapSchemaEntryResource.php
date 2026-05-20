<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;
use App\Jobs\Directory\SyncLdapSchemaEntriesJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapSchemaEntry;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
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

                Tables\Columns\TextColumn::make('syntax_oid')
                    ->label('Syntax')
                    ->state(fn ($record): string => static::safeValue($record, ['syntax_oid'], 'N/A'))
                    ->toggleable(),

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
                    ->options([
                        'attribute_type' => 'Attribute Type',
                        'object_class' => 'ObjectClass',
                        'ldap_syntax' => 'LDAP Syntax',
                        'matching_rule' => 'Matching Rule',
                        'matching_rule_use' => 'Matching Rule Use',
                    ])
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
                        \Filament\Forms\Components\Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(['' => 'All active LDAP connections'] + static::connectionOptions())
                            ->default('')
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(fn (array $data) => static::queueSchemaSync($data)),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn ($record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([])
            ->recordUrl(fn ($record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
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

            if (class_exists(SyncLdapSchemaEntriesJob::class)) {
                SyncLdapSchemaEntriesJob::dispatch($connectionId, SafeCommandExecutionLogger::id($execution));
            }

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
                $columns = \Illuminate\Support\Facades\Schema::getColumnListing('ldap_schema_entries');
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
