<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapRoleEntryResource\Pages;
use App\Models\Directory\LdapRoleEntry;
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

class LdapRoleEntryResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 50;


    protected static ?string $model = LdapRoleEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'LDAP Role';

    protected static ?string $pluralModelLabel = 'LDAP Roles';
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
                Section::make('Role Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('Source Group DN')->columnSpanFull(),
                        TextEntry::make('cn')->label('CN')->placeholder('N/A'),
                        TextEntry::make('role_key')->label('Role Key')->placeholder('N/A'),
                        TextEntry::make('role_name')->label('Role Name')->placeholder('N/A'),
                        TextEntry::make('role_type')
                            ->label('Role Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'admin_role' => 'danger',
                                'staff_role' => 'warning',
                                'student_role' => 'info',
                                'alumni_role' => 'success',
                                'external_role' => 'gray',
                                'app_role' => 'primary',
                                default => 'gray',
                            }),
                        TextEntry::make('role_scope')->label('Scope')->badge()->placeholder('N/A'),
                        TextEntry::make('application_key')->label('Application Key')->placeholder('N/A'),
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
                        TextEntry::make('resolved_user_count')->label('Resolved User Count'),
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

                Section::make('Members')
                    ->schema([
                        TextEntry::make('member_dns_text')
                            ->label('Member DNs')
                            ->columnSpanFull(),

                        TextEntry::make('member_uids_text')
                            ->label('Member UIDs')
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
                    'cn',
                    'role_key',
                    'role_name',
                    'role_type',
                    'role_scope',
                    'application_key',
                    'member_count',
                    'resolved_user_count',
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

                TextColumn::make('role_name')
                    ->label('Role')
                    ->searchable()
                    ->sortable()
                    ->limit(34)
                    ->placeholder('N/A'),

                TextColumn::make('role_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->limit(28)
                    ->placeholder('N/A'),

                TextColumn::make('role_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'admin_role' => 'danger',
                        'staff_role' => 'warning',
                        'student_role' => 'info',
                        'alumni_role' => 'success',
                        'external_role' => 'gray',
                        'app_role' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('role_scope')
                    ->label('Scope')
                    ->badge()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('application_key')
                    ->label('App')
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24)
                    ->placeholder('N/A'),

                TextColumn::make('member_count')
                    ->label('Members')
                    ->sortable(),

                TextColumn::make('resolved_user_count')
                    ->label('Resolved')
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

                SelectFilter::make('role_type')
                    ->label('Role Type')
                    ->options([
                        'admin_role' => 'Admin Role',
                        'staff_role' => 'Staff Role',
                        'student_role' => 'Student Role',
                        'alumni_role' => 'Alumni Role',
                        'external_role' => 'External Role',
                        'app_role' => 'App Role',
                        'user_role' => 'User Role',
                    ]),

                SelectFilter::make('role_scope')
                    ->label('Scope')
                    ->options([
                        'application' => 'Application',
                        'administration' => 'Administration',
                        'identity' => 'Identity',
                        'directory' => 'Directory',
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
            'index' => Pages\ListLdapRoleEntries::route('/'),
            'view' => Pages\ViewLdapRoleEntry::route('/{record}'),
        ];
    }
}
