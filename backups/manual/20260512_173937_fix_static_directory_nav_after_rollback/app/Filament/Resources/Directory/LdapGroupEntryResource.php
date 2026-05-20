<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapGroupEntryResource\Pages;
use App\Models\Directory\LdapGroupEntry;
use App\Services\Directory\LdapMembershipResolver;
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

class LdapGroupEntryResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 40;

    protected static ?string $model = LdapGroupEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Groups';

    protected static ?string $modelLabel = 'LDAP Group';

    protected static ?string $pluralModelLabel = 'LDAP Groups';
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
                Section::make('Group Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('DN')->columnSpanFull(),
                        TextEntry::make('cn')->label('CN')->placeholder('N/A'),
                        TextEntry::make('ou')->label('OU')->placeholder('N/A'),
                        TextEntry::make('description')->label('Description')->placeholder('N/A'),
                        TextEntry::make('group_type')
                            ->label('Group Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'app_group' => 'info',
                                'role_group' => 'warning',
                                'security_group' => 'danger',
                                'posix_group' => 'success',
                                'organizational_unit' => 'gray',
                                default => 'primary',
                            }),
                    ])
                    ->columns(3),

                Section::make('Status / Counters')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'missing_from_ldap' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('member_count')->label('Member Count'),
                        TextEntry::make('nested_group_count')->label('Nested Group Count'),
                    ])
                    ->columns(3),

                Section::make('LDAP Metadata')
                    ->schema([
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('entry_uuid')->label('Entry UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('object_classes_text')->label('Object Classes')->columnSpanFull(),
                        TextEntry::make('source_hash')->label('Source Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('last_seen_at')->label('Last Seen At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('last_synced_at')->label('Last Synced At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Resolved User Members')
                    ->description('Read-only relationship view generated from cached LDAP users and groups.')
                    ->schema([
                        TextEntry::make('resolved_user_count')
                            ->label('Resolved User Count')
                            ->state(fn (LdapGroupEntry $record): int => app(LdapMembershipResolver::class)->usersForGroupCount($record)),

                        TextEntry::make('resolved_users_text')
                            ->label('Resolved Users')
                            ->state(fn (LdapGroupEntry $record): string => app(LdapMembershipResolver::class)->usersForGroupText($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Members')
                    ->schema([
                        TextEntry::make('member_dns_text')
                            ->label('Raw Member DNs')
                            ->columnSpanFull(),

                        TextEntry::make('member_uids_text')
                            ->label('Raw Member UIDs')
                            ->columnSpanFull(),

                        TextEntry::make('nested_group_dns_text')
                            ->label('Nested Group DNs')
                            ->columnSpanFull(),
                    ]),

                Section::make('Raw Attributes')
                    ->schema([
                        TextEntry::make('attributes_json')
                            ->label('Attributes JSON')
                            ->columnSpanFull(),

                        TextEntry::make('operational_attributes_json')
                            ->label('Operational Attributes JSON')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
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
                    'dn',
                    'entry_uuid',
                    'cn',
                    'ou',
                    'description',
                    'group_type',
                    'member_count',
                    'nested_group_count',
                    'status',
                    'last_seen_at',
                    'last_synced_at',
                    'created_at',
                ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('cn')
                    ->label('CN')
                    ->searchable()
                    ->sortable()
                    ->limit(35)
                    ->placeholder('N/A'),

                TextColumn::make('ou')
                    ->label('OU')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->placeholder('N/A'),

                TextColumn::make('group_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'app_group' => 'info',
                        'role_group' => 'warning',
                        'security_group' => 'danger',
                        'posix_group' => 'success',
                        'organizational_unit' => 'gray',
                        default => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24)
                    ->placeholder('N/A'),

                TextColumn::make('member_count')
                    ->label('Members')
                    ->sortable(),

                TextColumn::make('nested_group_count')
                    ->label('Nested')
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
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'missing_from_ldap' => 'Missing From LDAP',
                    ]),

                SelectFilter::make('group_type')
                    ->label('Group Type')
                    ->options([
                        'ldap_group' => 'LDAP Group',
                        'app_group' => 'App Group',
                        'role_group' => 'Role Group',
                        'security_group' => 'Security Group',
                        'posix_group' => 'POSIX Group',
                        'organizational_unit' => 'Organizational Unit',
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
            'index' => Pages\ListLdapGroupEntries::route('/'),
            'view' => Pages\ViewLdapGroupEntry::route('/{record}'),
        ];
    }
}
