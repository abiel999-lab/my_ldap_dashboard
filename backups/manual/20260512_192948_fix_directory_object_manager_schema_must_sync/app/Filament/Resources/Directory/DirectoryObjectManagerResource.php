<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\DirectoryObjectManagerResource\Pages;
use App\Jobs\Directory\BulkGenericLdapEntryMutationJob;
use App\Jobs\Directory\GenericLdapEntryMutationJob;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class DirectoryObjectManagerResource extends Resource
{
    protected static ?string $model = LdapDirectoryEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationLabel = 'Directory Object Manager';

    protected static ?string $modelLabel = 'Directory Object';

    protected static ?string $pluralModelLabel = 'Directory Object Manager';

    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getSafeQuery())
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
                    ->limit(100),

                Tables\Columns\TextColumn::make('rdn')
                    ->label('RDN')
                    ->state(fn ($record): string => static::safeRdn((string) ($record->dn ?? '')))
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('dn', 'ilike', '%'.$search.'%');
                    }),

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
                    ->query(function (Builder $query): Builder {
                        return $query->where(function (Builder $query): void {
                            $query
                                ->whereNull('status')
                                ->orWhereNotIn('status', [
                                    'missing_from_ldap',
                                    'deleted_from_ldap',
                                ]);
                        });
                    }),

                Tables\Filters\Filter::make('only_ou')
                    ->label('Only OU')
                    ->query(fn (Builder $query): Builder => $query->where('dn', 'ilike', 'ou=%')),

                Tables\Filters\Filter::make('only_cn')
                    ->label('Only CN')
                    ->query(fn (Builder $query): Builder => $query->where('dn', 'ilike', 'cn=%')),
            ])
            ->headerActions([
                Action::make('createObject')
                    ->label('Create LDAP Object')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
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

                        Select::make('object_classes')
                            ->label('ObjectClasses')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options([
                                'top' => 'top',
                                'organizationalUnit' => 'organizationalUnit',
                                'organizationalRole' => 'organizationalRole',
                                'groupOfNames' => 'groupOfNames',
                                'groupOfUniqueNames' => 'groupOfUniqueNames',
                                'applicationProcess' => 'applicationProcess',
                                'device' => 'device',
                                'ipHost' => 'ipHost',
                                'simpleSecurityObject' => 'simpleSecurityObject',
                                'extensibleObject' => 'extensibleObject',
                            ])
                            ->default(['top'])
                            ->required(),

                        KeyValue::make('attributes')
                            ->label('Attributes')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->helperText('Multi-value pisahkan dengan koma. RDN attribute akan otomatis ditambahkan jika belum ada.'),
                    ])
                    ->action(function (array $data): void {
                        static::queueCreateObject($data);
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('addAttribute')
                        ->label('Add Attribute')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->form([
                            TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                            TagsInput::make('values')
                                ->label('Values')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'add_attribute', $data)),

                    Action::make('replaceAttribute')
                        ->label('Replace Attribute')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->form([
                            TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                            TagsInput::make('values')
                                ->label('New Values')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'replace_attribute', $data)),

                    Action::make('removeAttribute')
                        ->label('Remove Attribute')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'remove_attribute', $data)),

                    Action::make('addObjectClass')
                        ->label('Add ObjectClass')
                        ->icon('heroicon-o-cube')
                        ->color('primary')
                        ->form([
                            TextInput::make('object_class')
                                ->label('ObjectClass')
                                ->required(),
                            KeyValue::make('must_attributes')
                                ->label('MUST Attributes')
                                ->keyLabel('Attribute')
                                ->valueLabel('Value')
                                ->helperText('Isi jika objectClass membutuhkan MUST attribute tambahan.'),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'add_objectclass', $data)),

                    Action::make('removeObjectClass')
                        ->label('Remove ObjectClass')
                        ->icon('heroicon-o-cube-transparent')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            TextInput::make('object_class')
                                ->label('ObjectClass')
                                ->required(),
                            TagsInput::make('remove_attributes')
                                ->label('Remove attributes first')
                                ->helperText('Jika objectClass masih dipakai attribute tertentu, masukkan attribute yang perlu dihapus dulu.'),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'remove_objectclass', $data)),

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
                        ->label('Move DN')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('gray')
                        ->form([
                            TextInput::make('new_parent_dn')
                                ->label('New Parent DN')
                                ->placeholder('ou=target,dc=petra,dc=ac,dc=id')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueMutation($record, 'move_entry', $data)),

                    Action::make('deleteLdapEntry')
                        ->label('Delete LDAP')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete LDAP object?')
                        ->modalDescription('Entry akan dihapus dari LDAP lewat queue dan tercatat di Command Executions.')
                        ->action(fn ($record) => static::queueMutation($record, 'delete_entry', [])),
                ])
                    ->label('LDAP Actions')
                    ->icon('heroicon-o-command-line')
                    ->color('gray'),
            ])
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDirectoryObjects::route('/'),
        ];
    }

    public static function getSafeQuery(): Builder
    {
        return LdapDirectoryEntry::query();
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

    public static function objectClassSummary($record): string
    {
        $classes = static::extractObjectClasses($record);

        if ($classes === []) {
            return 'unknown';
        }

        return implode(', ', array_slice($classes, 0, 3));
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
                    if (str_contains($value, 'objectClass')) {
                        preg_match_all('/objectClass["\']?\s*[:=]\s*["\']?([a-zA-Z0-9_-]+)/i', $value, $matches);

                        if (! empty($matches[1])) {
                            return array_values(array_unique($matches[1]));
                        }
                    }

                    continue;
                }
            }

            if (is_array($value)) {
                $classes = $value['objectClass'] ?? $value['objectclass'] ?? null;

                if (is_array($classes)) {
                    return collect($classes)
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
                    return collect($value)
                        ->map(fn ($v) => (string) $v)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }
            }
        }

        return [];
    }

    public static function safeRdn(string $dn): string
    {
        if ($dn === '') {
            return 'N/A';
        }

        return explode(',', $dn, 2)[0] ?? $dn;
    }

    public static function rdnAttribute(string $dn): string
    {
        $rdn = static::safeRdn($dn);

        if (str_contains($rdn, '=')) {
            return explode('=', $rdn, 2)[0];
        }

        return 'cn';
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

            $dn = $rdnAttribute.'='.$rdnValue.','.$parentDn;

            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $attributes[$rdnAttribute] = $rdnValue;

            $payload = [
                'ldap_connection_id' => (int) $data['ldap_connection_id'],
                'dn' => $dn,
                'object_classes' => (array) ($data['object_classes'] ?? []),
                'attributes' => $attributes,
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
            SafeCommandExecutionLogger::createFailed(
                'ldap_directory_object_create_dispatch_failed',
                $e->getMessage(),
                $data
            );

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
}
