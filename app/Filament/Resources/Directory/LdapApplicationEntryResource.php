<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapApplicationEntryResource\Pages;
use App\Models\Directory\LdapApplicationEntry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LdapApplicationEntryResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\UnitEnum|null $navigationGroup = '1. DIRECTORY MANAGEMENT';
    protected static ?int $navigationSort = 70;


    protected static ?string $model = LdapApplicationEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $modelLabel = 'LDAP Application';

    protected static ?string $pluralModelLabel = 'LDAP Applications';
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('Source Group DN')->columnSpanFull(),
                        TextEntry::make('app_key')->label('App Key')->placeholder('N/A'),
                        TextEntry::make('app_name')->label('App Name')->placeholder('N/A'),
                        TextEntry::make('cn')->label('CN')->placeholder('N/A'),
                        TextEntry::make('application_type')
                            ->label('Application Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'web_app' => 'info',
                                'mobile_app' => 'success',
                                'network_access_app' => 'warning',
                                'api_app' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('integration_type')->label('Integration')->badge()->placeholder('N/A'),
                        TextEntry::make('environment')->label('Environment')->badge()->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Access Summary')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'missing_from_ldap' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('allowed_group_count')->label('Allowed Groups'),
                        TextEntry::make('required_role_count')->label('Required Roles'),
                        TextEntry::make('resolved_user_count')->label('Resolved Users'),
                    ])
                    ->columns(4),

                Section::make('Integration Flags')
                    ->schema([
                        IconEntry::make('oidc_enabled')->label('OIDC Enabled')->boolean(),
                        IconEntry::make('saml_enabled')->label('SAML Enabled')->boolean(),
                        IconEntry::make('api_access_enabled')->label('API Access Enabled')->boolean(),
                        TextEntry::make('source')->label('Source')->badge(),
                    ])
                    ->columns(4),

                Section::make('Source')
                    ->schema([
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('ldapGroupEntry.cn')->label('Source Group')->placeholder('N/A'),
                        TextEntry::make('entry_uuid')->label('Entry UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('object_classes_text')->label('Object Classes')->columnSpanFull(),
                        TextEntry::make('source_hash')->label('Source Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('last_seen_at')->label('Last Seen At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('last_synced_at')->label('Last Synced At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Access Rules')
                    ->schema([
                        TextEntry::make('allowed_group_dns_text')
                            ->label('Allowed Group DNs')
                            ->columnSpanFull(),

                        TextEntry::make('required_role_keys_text')
                            ->label('Required Role Keys')
                            ->columnSpanFull(),

                        TextEntry::make('required_role_ids_text')
                            ->label('Required Role IDs')
                            ->columnSpanFull(),

                        TextEntry::make('resolved_user_ids_text')
                            ->label('Resolved User IDs')
                            ->columnSpanFull(),
                    ]),

                Section::make('Raw / Metadata')
                    ->schema([
                        TextEntry::make('attributes_json')
                            ->label('Attributes JSON')
                            ->columnSpanFull(),

                        TextEntry::make('metadata_json')
                            ->label('Metadata JSON')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->actions([

                \Filament\Actions\ActionGroup::make([
                    \Filament\Actions\Action::make('addAttribute')
                        ->label('Add Attribute')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                            \Filament\Forms\Components\TagsInput::make('values')
                                ->label('Values')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'add_attribute', $data)),

                    \Filament\Actions\Action::make('replaceAttribute')
                        ->label('Replace Attribute')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                            \Filament\Forms\Components\TagsInput::make('values')
                                ->label('New Values')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'replace_attribute', $data)),

                    \Filament\Actions\Action::make('removeAttribute')
                        ->label('Remove Attribute')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            \Filament\Forms\Components\TextInput::make('attribute')
                                ->label('Attribute Name')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'remove_attribute', $data)),

                    \Filament\Actions\Action::make('addObjectClass')
                        ->label('Add ObjectClass')
                        ->icon('heroicon-o-cube')
                        ->color('primary')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('object_class')
                                ->label('ObjectClass')
                                ->required(),
                            \Filament\Forms\Components\KeyValue::make('must_attributes')
                                ->label('MUST Attributes')
                                ->keyLabel('Attribute')
                                ->valueLabel('Value')
                                ->helperText('Isi hanya jika objectClass membutuhkan MUST attribute tambahan.'),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'add_objectclass', $data)),

                    \Filament\Actions\Action::make('removeObjectClass')
                        ->label('Remove ObjectClass')
                        ->icon('heroicon-o-cube-transparent')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            \Filament\Forms\Components\TextInput::make('object_class')
                                ->label('ObjectClass')
                                ->required(),
                            \Filament\Forms\Components\TagsInput::make('remove_attributes')
                                ->label('Attributes to Remove First')
                                ->helperText('Jika objectClass masih dipakai attribute tertentu, masukkan attribute yang perlu dihapus dulu.'),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'remove_objectclass', $data)),

                    \Filament\Actions\Action::make('renameRdn')
                        ->label('Rename RDN')
                        ->icon('heroicon-o-pencil-square')
                        ->color('info')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('rdn_attribute')
                                ->label('RDN Attribute')
                                ->default('cn')
                                ->required(),
                            \Filament\Forms\Components\TextInput::make('rdn_value')
                                ->label('New RDN Value')
                                ->required(),
                            \Filament\Forms\Components\Toggle::make('delete_old_rdn')
                                ->label('Delete old RDN value')
                                ->default(true),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'rename_rdn', $data)),

                    \Filament\Actions\Action::make('moveOu')
                        ->label('Move OU')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('gray')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('new_parent_dn')
                                ->label('New Parent DN')
                                ->placeholder('ou=target,dc=petra,dc=ac,dc=id')
                                ->required(),
                        ])
                        ->action(fn ($record, array $data) => static::queueGenericLdapMutation($record, 'move_ou', $data)),

                    \Filament\Actions\Action::make('deleteLdapEntry')
                        ->label('Delete LDAP')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete LDAP entry?')
                        ->modalDescription('Entry akan dihapus dari LDAP lewat queue dan tercatat di Command Executions.')
                        ->action(fn ($record) => static::queueGenericLdapMutation($record, 'delete_entry', [])),
                ])
                    ->label('LDAP Actions')
                    ->icon('heroicon-o-command-line')
                    ->color('gray'),


                \Filament\Actions\Action::make('deleteFromLdap')
                    ->label('Delete LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP entry?')
                    ->modalDescription('Entry akan dihapus dari LDAP lewat queue. Semua hasil masuk Command Executions.')
                    ->action(function ($record): void {
                        try {
                            $execution = \App\Support\Operations\SafeCommandExecutionLogger::createQueued(
                                'ldap_entry_delete_queued',
                                'queued job: BulkDeleteLdapEntriesJob',
                                [
                                    'operation' => 'delete_single_ldap_entry',
                                    'model_class' => get_class($record),
                                    'record_id' => $record->id,
                                    'dn' => $record->dn ?? null,
                                    'queue' => 'ldap',
                                ]
                            );

                            \App\Jobs\Directory\BulkDeleteLdapEntriesJob::dispatch(
                                get_class($record),
                                [$record->id],
                                \App\Support\Operations\SafeCommandExecutionLogger::id($execution),
                                class_basename($record)
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('LDAP delete queued')
                                ->body('Command Execution ID: '.(\App\Support\Operations\SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \App\Support\Operations\SafeCommandExecutionLogger::createFailed(
                                'ldap_entry_delete_dispatch_failed',
                                $e->getMessage(),
                                [
                                    'record_id' => $record->id ?? null,
                                    'dn' => $record->dn ?? null,
                                ]
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('LDAP delete failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([

                \Filament\Actions\BulkAction::make('bulkDeleteFromLdap')
                    ->label('Delete Selected From LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected LDAP entries?')
                    ->modalDescription('Semua entry terpilih akan dihapus dari LDAP lewat queue. Hasilnya masuk Command Executions.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records): void {
                        try {
                            $ids = $records->pluck('id')->values()->all();
                            $first = $records->first();

                            if (! $first) {
                                throw new \RuntimeException('No selected records.');
                            }

                            $modelClass = get_class($first);

                            $execution = \App\Support\Operations\SafeCommandExecutionLogger::createQueued(
                                'ldap_entries_bulk_delete_queued',
                                'queued job: BulkDeleteLdapEntriesJob',
                                [
                                    'operation' => 'bulk_delete_selected_ldap_entries',
                                    'model_class' => $modelClass,
                                    'record_count' => count($ids),
                                    'record_ids' => $ids,
                                    'queue' => 'ldap',
                                ]
                            );

                            \App\Jobs\Directory\BulkDeleteLdapEntriesJob::dispatch(
                                $modelClass,
                                $ids,
                                \App\Support\Operations\SafeCommandExecutionLogger::id($execution),
                                class_basename($modelClass)
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('Bulk delete queued')
                                ->body('Total records: '.count($ids).' | Command Execution ID: '.(\App\Support\Operations\SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \App\Support\Operations\SafeCommandExecutionLogger::createFailed(
                                'ldap_entries_bulk_delete_dispatch_failed',
                                $e->getMessage()
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('Bulk delete dispatch failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'ldap_connection_id',
                    'ldap_group_entry_id',
                    'dn',
                    'entry_uuid',
                    'app_key',
                    'app_name',
                    'cn',
                    'application_type',
                    'integration_type',
                    'environment',
                    'allowed_group_count',
                    'required_role_count',
                    'resolved_user_count',
                    'oidc_enabled',
                    'saml_enabled',
                    'api_access_enabled',
                    'source',
                    'status',
                    'last_seen_at',
                    'last_synced_at',
                    'created_at',
                ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('app_name')
                    ->label('Application')
                    ->searchable()
                    ->sortable()
                    ->limit(34)
                    ->placeholder('N/A'),

                TextColumn::make('app_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->limit(28)
                    ->placeholder('N/A'),

                TextColumn::make('application_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'web_app' => 'info',
                        'mobile_app' => 'success',
                        'network_access_app' => 'warning',
                        'api_app' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('integration_type')
                    ->label('Integration')
                    ->badge()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24)
                    ->placeholder('N/A'),

                TextColumn::make('allowed_group_count')
                    ->label('Groups')
                    ->sortable(),

                TextColumn::make('required_role_count')
                    ->label('Roles')
                    ->sortable(),

                TextColumn::make('resolved_user_count')
                    ->label('Users')
                    ->sortable(),

                IconColumn::make('oidc_enabled')
                    ->label('OIDC')
                    ->boolean(),

                IconColumn::make('saml_enabled')
                    ->label('SAML')
                    ->boolean(),

                IconColumn::make('api_access_enabled')
                    ->label('API')
                    ->boolean(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'missing_from_ldap' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('dn')
                    ->label('DN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(90),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'missing_from_ldap' => 'Missing From LDAP',
                    ]),

                SelectFilter::make('application_type')
                    ->label('Application Type')
                    ->options([
                        'web_app' => 'Web App',
                        'mobile_app' => 'Mobile App',
                        'network_access_app' => 'Network Access App',
                        'api_app' => 'API App',
                        'ldap_app_group' => 'LDAP App Group',
                    ]),

                SelectFilter::make('integration_type')
                    ->label('Integration')
                    ->options([
                        'oidc' => 'OIDC',
                        'saml' => 'SAML',
                        'radius' => 'RADIUS',
                        'api' => 'API',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapApplicationEntries::route('/'),
            'view' => Pages\ViewLdapApplicationEntry::route('/{record}'),
        ];
    }

    public static function queueGenericLdapMutation($record, string $operation, array $payload = []): void
    {
        try {
            $execution = \App\Support\Operations\SafeCommandExecutionLogger::createQueued(
                'ldap_generic_entry_mutation_queued',
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

            \App\Jobs\Directory\GenericLdapEntryMutationJob::dispatch(
                get_class($record),
                (int) $record->id,
                $operation,
                $payload,
                \App\Support\Operations\SafeCommandExecutionLogger::id($execution)
            );

            \Filament\Notifications\Notification::make()
                ->title('LDAP operation queued')
                ->body('Operation: '.$operation.' | Command Execution ID: '.(\App\Support\Operations\SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            \App\Support\Operations\SafeCommandExecutionLogger::createFailed(
                'ldap_generic_entry_mutation_dispatch_failed',
                $e->getMessage(),
                [
                    'operation' => $operation,
                    'record_id' => $record->id ?? null,
                    'dn' => $record->dn ?? null,
                    'payload' => $payload,
                ]
            );

            \Filament\Notifications\Notification::make()
                ->title('LDAP operation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

}
