<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapUserEntryResource\Pages;
use App\Models\Directory\LdapUserEntry;
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
    protected static ?string $model = LdapUserEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'LDAP User';

    protected static ?string $pluralModelLabel = 'LDAP Users';

    protected static ?int $navigationSort = 10;

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
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('DN')->columnSpanFull(),
                        TextEntry::make('uid')->label('UID')->placeholder('N/A'),
                        TextEntry::make('cn')->label('CN')->placeholder('N/A'),
                        TextEntry::make('sn')->label('SN')->placeholder('N/A'),
                        TextEntry::make('given_name')->label('Given Name')->placeholder('N/A'),
                        TextEntry::make('display_label')->label('Display Name')->placeholder('N/A'),
                        TextEntry::make('mail')->label('Email')->placeholder('N/A'),
                        TextEntry::make('employee_number')->label('Employee Number')->placeholder('N/A'),
                        TextEntry::make('employee_type')->label('Employee Type')->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Status')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'missing_from_ldap' => 'warning',
                                'disabled' => 'gray',
                                'locked' => 'danger',
                                default => 'gray',
                            }),
                        IconEntry::make('is_disabled')->label('Disabled')->boolean(),
                        IconEntry::make('is_locked')->label('Locked')->boolean(),
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

                Section::make('Group Membership Cache')
                    ->schema([
                        TextEntry::make('group_dns_text')
                            ->label('Group DNs')
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
