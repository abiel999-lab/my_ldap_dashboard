<?php

namespace App\Filament\Resources\Directory;

use App\Support\Operations\SafeCommandExecutionLogger;

use App\Models\Operations\CommandExecution;

use App\Jobs\Directory\BulkDeleteUsersFromLdapJob;

use App\Jobs\Directory\BulkMoveUsersToOuJob;

use App\Jobs\Directory\SyncUsersBatchJob;

use App\Services\Directory\LdapSingleUserSyncService;

use App\Services\Directory\LdapUserLifecycleService;

use Filament\Schemas\Components\Tabs\Tab;

use Filament\Schemas\Components\Tabs;

use Filament\Schemas\Components\Grid;

use Filament\Infolists\Components\RepeatableEntry;

use App\Services\Ldap\LdapEntryInspectorService;

use App\Filament\Resources\Directory\LdapUserEntryResource\Pages;
use App\Models\Directory\LdapUserEntry;
use App\Services\Directory\LdapMembershipResolver;
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

class LdapUserEntryResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 30;


    protected static ?string $model = LdapUserEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'LDAP User';

    protected static ?string $pluralModelLabel = 'LDAP Users';
    public static function canCreate(): bool
    {
        return false
            ->actions([
                \Filament\Actions\ViewAction::make(),

                \Filament\Actions\Action::make('syncFromTable')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function ($record): void {
                        $result = app(LdapSingleUserSyncService::class)->sync($record);

                        if ($result['ok'] ?? false) {
                            \Filament\Notifications\Notification::make()
                                ->title('User synced')
                                ->body(isset($result['command_execution_id']) ? 'Command Execution ID: '.$result['command_execution_id'] : null)
                                ->success()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Sync failed')
                                ->body($result['message'] ?? 'Unknown error.')
                                ->danger()
                                ->send();
                        }
                    }),

                \Filament\Actions\Action::make('deleteFromLdap')
                    ->label('Delete LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP user?')
                    ->modalDescription('User akan dihapus dari LDAP dan disembunyikan dari default list.')
                    ->action(function ($record): void {
                        $result = app(LdapUserLifecycleService::class)->deleteUser($record);

                        if ($result['ok'] ?? false) {
                            \Filament\Notifications\Notification::make()
                                ->title('User deleted from LDAP')
                                ->body(isset($result['command_execution_id']) ? 'Command Execution ID: '.$result['command_execution_id'] : null)
                                ->success()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Delete failed')
                                ->body($result['message'] ?? 'Unknown error.')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkAction::make('bulkSyncUsers')
                    ->label('Sync Selected Users')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records): void {
                        $ok = 0;
                        $failed = 0;

                        foreach ($records as $record) {
                            $result = app(LdapSingleUserSyncService::class)->sync($record);

                            if ($result['ok'] ?? false) {
                                $ok++;
                            } else {
                                $failed++;
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Bulk sync finished')
                            ->body('Success: '.$ok.' | Failed: '.$failed)
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\BulkAction::make('bulkMoveOu')
                    ->label('Move Selected to OU')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('new_parent_dn')
                            ->label('New Parent DN')
                            ->placeholder('ou=students,ou=people,dc=petra,dc=ac,dc=id')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records, array $data): void {
                        $ok = 0;
                        $failed = 0;

                        foreach ($records as $record) {
                            $result = app(LdapUserLifecycleService::class)->moveOu(
                                $record,
                                (string) ($data['new_parent_dn'] ?? '')
                            );

                            if ($result['ok'] ?? false) {
                                $ok++;
                            } else {
                                $failed++;
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Bulk move OU finished')
                            ->body('Success: '.$ok.' | Failed: '.$failed)
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\BulkAction::make('bulkDeleteFromLdap')
                    ->label('Delete Selected From LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected LDAP users?')
                    ->modalDescription('Semua user terpilih akan dihapus dari LDAP dan disembunyikan dari default list.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records): void {
                        $ok = 0;
                        $failed = 0;

                        foreach ($records as $record) {
                            $result = app(LdapUserLifecycleService::class)->deleteUser($record);

                            if ($result['ok'] ?? false) {
                                $ok++;
                            } else {
                                $failed++;
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Bulk delete finished')
                            ->body('Success: '.$ok.' | Failed: '.$failed)
                            ->success()
                            ->send();
                    }),
            ]);
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
                Tabs::make('LDAP User Detail')
                    ->tabs([
                        Tab::make('Overview')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Identity')
                                            ->schema([
                                                TextEntry::make('dn')
                                                    ->label('DN')
                                                    ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.dn', 'N/A'))
                                                    ->columnSpanFull(),

                                                Grid::make(3)
                                                    ->schema([
                                                        TextEntry::make('uid')
                                                            ->label('UID')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.uid', 'N/A')),

                                                        TextEntry::make('cn')
                                                            ->label('CN')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.cn', 'N/A')),

                                                        TextEntry::make('sn')
                                                            ->label('SN')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.sn', 'N/A')),

                                                        TextEntry::make('given_name')
                                                            ->label('Given Name')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.given_name', 'N/A')),

                                                        TextEntry::make('display_name')
                                                            ->label('Display Name')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.display_name', 'N/A')),

                                                        TextEntry::make('mail')
                                                            ->label('Mail')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.mail', 'N/A')),
                                                    ]),

                                                Grid::make(3)
                                                    ->schema([
                                                        TextEntry::make('rdn')
                                                            ->label('RDN')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.rdn', 'N/A')),

                                                        TextEntry::make('parent_dn')
                                                            ->label('Parent DN')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.parent_dn', 'N/A')),

                                                        TextEntry::make('ou')
                                                            ->label('OU')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.ou', 'N/A')),
                                                    ]),
                                            ]),

                                        Section::make('Status & Summary')
                                            ->schema([
                                                Grid::make(2)
                                                    ->schema([
                                                        TextEntry::make('status')
                                                            ->label('Status')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.status', 'N/A'))
                                                            ->badge(),

                                                        TextEntry::make('connection')
                                                            ->label('LDAP Connection')
                                                            ->state(fn ($record): string => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'overview.connection', 'N/A')),

                                                        TextEntry::make('object_class_count')
                                                            ->label('Object Classes')
                                                            ->state(fn ($record): int => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'summary.object_class_count', 0)),

                                                        TextEntry::make('normal_attribute_count')
                                                            ->label('Directory Attributes')
                                                            ->state(fn ($record): int => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'summary.normal_attribute_count', 0)),

                                                        TextEntry::make('operational_attribute_count')
                                                            ->label('Operational Attributes')
                                                            ->state(fn ($record): int => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'summary.operational_attribute_count', 0)),

                                                        TextEntry::make('membership_count')
                                                            ->label('Memberships')
                                                            ->state(fn ($record): int => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'summary.membership_count', 0)),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Directory Attributes')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Section::make('Directory Attributes')
                                    ->description('Attributes stored on this LDAP entry. Editing actions will be added here next.')
                                    ->schema([
                                        RepeatableEntry::make('directory_attributes')
                                            ->label('')
                                            ->state(fn ($record): array => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'directory_attributes', []))
                                            ->schema([
                                                Grid::make(4)
                                                    ->schema([
                                                        TextEntry::make('name')
                                                            ->label('Attribute'),

                                                        TextEntry::make('value_count')
                                                            ->label('Value Count'),

                                                        TextEntry::make('type')
                                                            ->label('Type')
                                                            ->badge(),

                                                        TextEntry::make('values')
                                                            ->label('Values')
                                                            ->bulleted()
                                                            ->listWithLineBreaks(),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Object Classes')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Section::make('Object Classes')
                                    ->description('Object classes currently attached to this LDAP entry.')
                                    ->schema([
                                        RepeatableEntry::make('object_classes')
                                            ->label('')
                                            ->state(fn ($record): array => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'object_classes', []))
                                            ->schema([
                                                Grid::make(2)
                                                    ->schema([
                                                        TextEntry::make('no')
                                                            ->label('No'),

                                                        TextEntry::make('name')
                                                            ->label('Object Class'),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Operational Attributes')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Section::make('Operational / Read-Only Attributes')
                                    ->description('Server-managed attributes. These are shown for inspection and are not edited manually.')
                                    ->schema([
                                        RepeatableEntry::make('operational_attributes')
                                            ->label('')
                                            ->state(fn ($record): array => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'operational_attributes', []))
                                            ->schema([
                                                Grid::make(4)
                                                    ->schema([
                                                        TextEntry::make('name')
                                                            ->label('Attribute'),

                                                        TextEntry::make('value_count')
                                                            ->label('Value Count'),

                                                        TextEntry::make('type')
                                                            ->label('Type')
                                                            ->badge(),

                                                        TextEntry::make('values')
                                                            ->label('Values')
                                                            ->bulleted()
                                                            ->listWithLineBreaks(),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Membership')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Section::make('Group Membership')
                                    ->description('Groups, roles, and application groups currently linked through memberOf.')
                                    ->schema([
                                        RepeatableEntry::make('memberships')
                                            ->label('')
                                            ->state(fn ($record): array => data_get(app(LdapEntryInspectorService::class)->inspect($record), 'memberships', []))
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        TextEntry::make('no')
                                                            ->label('No'),

                                                        TextEntry::make('cn')
                                                            ->label('CN'),

                                                        TextEntry::make('dn')
                                                            ->label('Group DN'),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', [
                        'missing_from_ldap',
                        'deleted_from_ldap',
                    ]);
            });
    }

    public static function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return $table
            ->modifyQueryUsing(function (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder {
                return $query
                    ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
                        $query
                            ->whereNull('status')
                            ->orWhereNotIn('status', [
                                'missing_from_ldap',
                                'deleted_from_ldap',
                            ]);
                    });
            })
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('uid')
                    ->label('UID')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                \Filament\Tables\Columns\TextColumn::make('cn')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                \Filament\Tables\Columns\TextColumn::make('mail')
                    ->label('Email')
                    ->searchable()
                    ->wrap(),

                \Filament\Tables\Columns\TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->default(fn ($record): string => (string) ($record->ldap_connection_id ?? 'N/A'))
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->default('active')
                    ->sortable(),

                \Filament\Tables\Columns\IconColumn::make('disabled')
                    ->label('Disabled')
                    ->boolean(),

                \Filament\Tables\Columns\IconColumn::make('locked')
                    ->label('Locked')
                    ->boolean(),

                \Filament\Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime('M j, Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('ldap_connection_id')
                    ->label('LDAP Connection')
                    ->relationship('ldapConnection', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'active',
                        'disabled' => 'disabled',
                        'locked' => 'locked',
                        'missing_from_ldap' => 'missing_from_ldap',
                        'deleted_from_ldap' => 'deleted_from_ldap',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),

                \Filament\Actions\Action::make('syncFromTable')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function ($record): void {
                        try {
                            $execution = SafeCommandExecutionLogger::createQueued(
                                'ldap_user_sync_queued',
                                'queued job: SyncUsersBatchJob',
                                [
                                    'operation' => 'sync_single_user_from_table',
                                    'user_id' => $record->id,
                                    'dn' => $record->dn,
                                    'queue' => 'ldap',
                                ]
                            );

                            SyncUsersBatchJob::dispatch([$record->id], SafeCommandExecutionLogger::id($execution));

                            \Filament\Notifications\Notification::make()
                                ->title('User sync queued')
                                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            SafeCommandExecutionLogger::createFailed('ldap_user_sync_dispatch_failed', $e->getMessage(), [
                                'user_id' => $record->id ?? null,
                                'dn' => $record->dn ?? null,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Sync dispatch failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                \Filament\Actions\Action::make('deleteFromLdap')
                    ->label('Delete LDAP')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete LDAP user?')
                    ->modalDescription('User akan dihapus dari LDAP lewat queue dan disembunyikan dari default list.')
                    ->action(function ($record): void {
                        try {
                            $execution = SafeCommandExecutionLogger::createQueued(
                                'ldap_user_delete_queued',
                                'queued job: BulkDeleteUsersFromLdapJob',
                                [
                                    'operation' => 'delete_single_user_from_table',
                                    'user_id' => $record->id,
                                    'dn' => $record->dn,
                                    'queue' => 'ldap',
                                ]
                            );

                            BulkDeleteUsersFromLdapJob::dispatch([$record->id], SafeCommandExecutionLogger::id($execution));

                            \Filament\Notifications\Notification::make()
                                ->title('Delete LDAP user queued')
                                ->body('Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            SafeCommandExecutionLogger::createFailed('ldap_user_delete_dispatch_failed', $e->getMessage(), [
                                'user_id' => $record->id ?? null,
                                'dn' => $record->dn ?? null,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Delete dispatch failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('bulkSyncUsers')
                        ->label('Sync Selected Users')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records): void {
                            try {
                                $ids = $records->pluck('id')->values()->all();

                                $execution = SafeCommandExecutionLogger::createQueued(
                                    'ldap_users_bulk_sync_queued',
                                    'queued job: SyncUsersBatchJob',
                                    [
                                        'operation' => 'bulk_sync_selected_users',
                                        'user_count' => count($ids),
                                        'user_ids' => $ids,
                                        'queue' => 'ldap',
                                    ]
                                );

                                SyncUsersBatchJob::dispatch($ids, SafeCommandExecutionLogger::id($execution));

                                \Filament\Notifications\Notification::make()
                                    ->title('Bulk sync queued')
                                    ->body('Total users: '.count($ids).' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                SafeCommandExecutionLogger::createFailed('ldap_users_bulk_sync_dispatch_failed', $e->getMessage());

                                \Filament\Notifications\Notification::make()
                                    ->title('Bulk sync dispatch failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    \Filament\Actions\BulkAction::make('bulkMoveOu')
                        ->label('Move Selected to OU')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('new_parent_dn')
                                ->label('New Parent DN')
                                ->placeholder('ou=students,ou=people,dc=petra,dc=ac,dc=id')
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records, array $data): void {
                            try {
                                $ids = $records->pluck('id')->values()->all();
                                $newParentDn = trim((string) ($data['new_parent_dn'] ?? ''));

                                if ($newParentDn === '') {
                                    throw new \RuntimeException('New Parent DN is required.');
                                }

                                $execution = SafeCommandExecutionLogger::createQueued(
                                    'ldap_users_bulk_move_ou_queued',
                                    'queued job: BulkMoveUsersToOuJob',
                                    [
                                        'operation' => 'bulk_move_selected_users_to_ou',
                                        'user_count' => count($ids),
                                        'user_ids' => $ids,
                                        'new_parent_dn' => $newParentDn,
                                        'queue' => 'ldap',
                                    ]
                                );

                                BulkMoveUsersToOuJob::dispatch($ids, $newParentDn, SafeCommandExecutionLogger::id($execution));

                                \Filament\Notifications\Notification::make()
                                    ->title('Bulk move OU queued')
                                    ->body('Total users: '.count($ids).' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                SafeCommandExecutionLogger::createFailed('ldap_users_bulk_move_ou_dispatch_failed', $e->getMessage(), [
                                    'new_parent_dn' => $data['new_parent_dn'] ?? null,
                                ]);

                                \Filament\Notifications\Notification::make()
                                    ->title('Bulk move OU dispatch failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    \Filament\Actions\BulkAction::make('bulkDeleteFromLdap')
                        ->label('Delete Selected From LDAP')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected LDAP users?')
                        ->modalDescription('Semua user terpilih akan dihapus dari LDAP lewat queue dan disembunyikan dari default list.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records): void {
                            try {
                                $ids = $records->pluck('id')->values()->all();

                                $execution = SafeCommandExecutionLogger::createQueued(
                                    'ldap_users_bulk_delete_queued',
                                    'queued job: BulkDeleteUsersFromLdapJob',
                                    [
                                        'operation' => 'bulk_delete_selected_users_from_ldap',
                                        'user_count' => count($ids),
                                        'user_ids' => $ids,
                                        'queue' => 'ldap',
                                    ]
                                );

                                BulkDeleteUsersFromLdapJob::dispatch($ids, SafeCommandExecutionLogger::id($execution));

                                \Filament\Notifications\Notification::make()
                                    ->title('Bulk delete queued')
                                    ->body('Total users: '.count($ids).' | Command Execution ID: '.(SafeCommandExecutionLogger::id($execution) ?? 'N/A'))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                SafeCommandExecutionLogger::createFailed('ldap_users_bulk_delete_dispatch_failed', $e->getMessage());

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
            ->paginated([10, 25, 50, 100]);
    }





    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapUserEntries::route('/'),
            'view' => Pages\ViewLdapUserEntry::route('/{record}'),
        ];
    }
}
