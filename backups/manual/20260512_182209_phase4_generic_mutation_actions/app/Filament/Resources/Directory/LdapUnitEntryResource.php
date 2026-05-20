<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapUnitEntryResource\Pages;
use App\Models\Directory\LdapUnitEntry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LdapUnitEntryResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 60;

    protected static ?string $model = LdapUnitEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Units / OU';

    protected static ?string $modelLabel = 'LDAP Unit / OU';

    protected static ?string $pluralModelLabel = 'LDAP Units / OU';

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
                Section::make('Unit Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('DN')->columnSpanFull(),
                        TextEntry::make('parent_dn')->label('Parent DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('ou')->label('OU')->placeholder('N/A'),
                        TextEntry::make('unit_key')->label('Unit Key')->placeholder('N/A'),
                        TextEntry::make('unit_name')->label('Unit Name')->placeholder('N/A'),
                        TextEntry::make('unit_type')
                            ->label('Unit Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'people_container' => 'info',
                                'groups_container' => 'success',
                                'applications_container' => 'primary',
                                'roles_container' => 'warning',
                                'devices_container' => 'danger',
                                'services_container' => 'gray',
                                'policies_container' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('tree_level')->label('Tree Level'),
                    ])
                    ->columns(3),

                Section::make('Counters')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'missing_from_ldap' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('direct_child_count')->label('Direct Child OUs'),
                        TextEntry::make('user_count')->label('Users Under DN'),
                        TextEntry::make('group_count')->label('Groups Under DN'),
                        TextEntry::make('source')->label('Source')->badge(),
                    ])
                    ->columns(5),

                Section::make('Source')
                    ->schema([
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('ldapGroupEntry.ou')->label('Source OU')->placeholder('N/A'),
                        TextEntry::make('entry_uuid')->label('Entry UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('object_classes_text')->label('Object Classes')->columnSpanFull(),
                        TextEntry::make('source_hash')->label('Source Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('last_seen_at')->label('Last Seen At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('last_synced_at')->label('Last Synced At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Tree')
                    ->schema([
                        TextEntry::make('child_unit_dns_text')
                            ->label('Child Unit DNs')
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
                    ->modalHeading('Delete selected LDAP OUs?')
                    ->modalDescription('OU akan dihapus lewat queue. Jika masih punya child, LDAP akan menolak dan masuk Command Executions.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records): void {
                        $ids = $records->pluck('id')->values()->all();
                        $first = $records->first();

                        if (! $first) {
                            return;
                        }

                        $modelClass = get_class($first);

                        $execution = \App\Support\Operations\SafeCommandExecutionLogger::createQueued(
                            'ldap_unit_bulk_delete_queued',
                            'queued job: BulkDeleteLdapEntriesJob',
                            [
                                'operation' => 'bulk_delete_selected_ldap_units',
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
                            'LDAP Units / OU'
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('OU bulk delete queued')
                            ->body('Total: '.count($ids).' | Command Execution ID: '.(\App\Support\Operations\SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                            ->success()
                            ->send();
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
                    'parent_dn',
                    'entry_uuid',
                    'ou',
                    'unit_key',
                    'unit_name',
                    'unit_type',
                    'tree_level',
                    'direct_child_count',
                    'user_count',
                    'group_count',
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

                TextColumn::make('unit_name')
                    ->label('Unit')
                    ->searchable()
                    ->sortable()
                    ->limit(34)
                    ->placeholder('N/A'),

                TextColumn::make('unit_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->limit(28)
                    ->placeholder('N/A'),

                TextColumn::make('unit_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'people_container' => 'info',
                        'groups_container' => 'success',
                        'applications_container' => 'primary',
                        'roles_container' => 'warning',
                        'devices_container' => 'danger',
                        'services_container' => 'gray',
                        'policies_container' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('tree_level')
                    ->label('Level')
                    ->sortable(),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24)
                    ->placeholder('N/A'),

                TextColumn::make('direct_child_count')
                    ->label('Child OUs')
                    ->sortable(),

                TextColumn::make('user_count')
                    ->label('Users')
                    ->sortable(),

                TextColumn::make('group_count')
                    ->label('Groups')
                    ->sortable(),

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

                SelectFilter::make('unit_type')
                    ->label('Unit Type')
                    ->options([
                        'organizational_unit' => 'Organizational Unit',
                        'people_container' => 'People Container',
                        'groups_container' => 'Groups Container',
                        'applications_container' => 'Applications Container',
                        'roles_container' => 'Roles Container',
                        'units_container' => 'Units Container',
                        'devices_container' => 'Devices Container',
                        'services_container' => 'Services Container',
                        'policies_container' => 'Policies Container',
                        'student_unit' => 'Student Unit',
                        'staff_unit' => 'Staff Unit',
                        'alumni_unit' => 'Alumni Unit',
                        'external_unit' => 'External Unit',
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
            'index' => Pages\ListLdapUnitEntries::route('/'),
            'view' => Pages\ViewLdapUnitEntry::route('/{record}'),
        ];
    }
}
