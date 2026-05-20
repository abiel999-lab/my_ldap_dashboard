<?php

namespace App\Filament\Resources\Directory;

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



    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhere('status', '!=', 'missing_from_ldap');
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'ldap_connection_id',
                    'dn',
                    'entry_uuid',
                    'uid',
                    'cn',
                    'sn',
                    'display_name',
                    'mail',
                    'employee_number',
                    'status',
                    'is_disabled',
                    'is_locked',
                    'last_seen_at',
                    'last_synced_at',
                    'created_at',
                ]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('uid')
                    ->label('UID')
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('cn')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(35)
                    ->placeholder('N/A'),

                TextColumn::make('mail')
                    ->label('Email')
                    ->searchable()
                    ->limit(35)
                    ->placeholder('N/A'),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24)
                    ->placeholder('N/A'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'missing_from_ldap' => 'warning',
                        'disabled' => 'gray',
                        'locked' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_disabled')
                    ->label('Disabled')
                    ->boolean(),

                IconColumn::make('is_locked')
                    ->label('Locked')
                    ->boolean(),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('dn')
                    ->label('DN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(70),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'missing_from_ldap' => 'Missing From LDAP',
                        'disabled' => 'Disabled',
                        'locked' => 'Locked',
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
            'index' => Pages\ListLdapUserEntries::route('/'),
            'view' => Pages\ViewLdapUserEntry::route('/{record}'),
        ];
    }
}
