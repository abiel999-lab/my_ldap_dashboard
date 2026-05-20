<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapDirectoryEntryResource\Pages;
use App\Models\Directory\LdapDirectoryEntry;
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

class LdapDirectoryEntryResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 10;

    protected static ?string $model = LdapDirectoryEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationLabel = 'Directory Explorer';

    protected static ?string $modelLabel = 'LDAP Directory Entry';

    protected static ?string $pluralModelLabel = 'LDAP Directory Explorer';

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
                Section::make('Entry Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('dn')->label('DN')->columnSpanFull(),
                        TextEntry::make('parent_dn')->label('Parent DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('rdn')->label('RDN')->placeholder('N/A'),
                        TextEntry::make('rdn_attribute')->label('RDN Attribute')->placeholder('N/A'),
                        TextEntry::make('rdn_value')->label('RDN Value')->placeholder('N/A'),
                        TextEntry::make('entry_uuid')->label('Entry UUID')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Detected Type')
                    ->schema([
                        TextEntry::make('entry_type')
                            ->label('Entry Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'user' => 'info',
                                'group' => 'success',
                                'role' => 'warning',
                                'application' => 'primary',
                                'unit_ou' => 'gray',
                                'device' => 'danger',
                                'service_account' => 'gray',
                                'policy' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('entry_category')->label('Category')->badge()->placeholder('N/A'),
                        TextEntry::make('entryTypeRule.name')->label('Matched Rule')->placeholder('N/A'),
                        TextEntry::make('tree_level')->label('Tree Level'),
                        TextEntry::make('child_count')->label('Child Count'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'missing_from_ldap' => 'warning',
                                default => 'gray',
                            }),
                    ])
                    ->columns(6),

                Section::make('Mapped Attributes')
                    ->schema([
                        TextEntry::make('identifier_attribute')->label('Identifier Attribute')->placeholder('N/A'),
                        TextEntry::make('identifier_value')->label('Identifier Value')->placeholder('N/A'),
                        TextEntry::make('display_attribute')->label('Display Attribute')->placeholder('N/A'),
                        TextEntry::make('display_value')->label('Display Value')->placeholder('N/A'),
                        TextEntry::make('email_attribute')->label('Email Attribute')->placeholder('N/A'),
                        TextEntry::make('email_value')->label('Email Value')->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Object Classes')
                    ->schema([
                        TextEntry::make('object_classes_text')
                            ->label('objectClass')
                            ->columnSpanFull(),
                    ]),

                Section::make('Source')
                    ->schema([
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('source')->label('Source')->badge(),
                        TextEntry::make('source_hash')->label('Source Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('last_seen_at')->label('Last Seen At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('last_synced_at')->label('Last Synced At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Raw Attributes')
                    ->schema([
                        TextEntry::make('attributes_json')
                            ->label('Attributes JSON')
                            ->columnSpanFull(),

                        TextEntry::make('operational_attributes_json')
                            ->label('Operational Attributes JSON')
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'missing_from_ldap'))
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'ldap_connection_id',
                    'ldap_entry_type_rule_id',
                    'dn',
                    'parent_dn',
                    'rdn',
                    'rdn_attribute',
                    'rdn_value',
                    'entry_uuid',
                    'entry_type',
                    'entry_category',
                    'identifier_value',
                    'display_value',
                    'email_value',
                    'tree_level',
                    'child_count',
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

                TextColumn::make('display_value')
                    ->label('Display')
                    ->searchable()
                    ->sortable()
                    ->limit(36)
                    ->placeholder('N/A'),

                TextColumn::make('identifier_value')
                    ->label('Identifier')
                    ->searchable()
                    ->sortable()
                    ->limit(32)
                    ->placeholder('N/A'),

                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'user' => 'info',
                        'group' => 'success',
                        'role' => 'warning',
                        'application' => 'primary',
                        'unit_ou' => 'gray',
                        'device' => 'danger',
                        'service_account' => 'gray',
                        'policy' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('entry_category')
                    ->label('Category')
                    ->badge()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('rdn')
                    ->label('RDN')
                    ->searchable()
                    ->sortable()
                    ->limit(34)
                    ->placeholder('N/A'),

                TextColumn::make('tree_level')
                    ->label('Level')
                    ->sortable(),

                TextColumn::make('child_count')
                    ->label('Child')
                    ->sortable(),

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
                SelectFilter::make('entry_type')
                    ->label('Entry Type')
                    ->options([
                        'user' => 'User',
                        'group' => 'Group',
                        'role' => 'Role',
                        'application' => 'Application',
                        'unit_ou' => 'Unit / OU',
                        'device' => 'Device',
                        'service_account' => 'Service Account',
                        'policy' => 'Policy',
                        'generic_entry' => 'Generic Entry',
                    ]),

                SelectFilter::make('entry_category')
                    ->label('Category')
                    ->options([
                        'identity' => 'Identity',
                        'authorization' => 'Authorization',
                        'application_access' => 'Application Access',
                        'structure' => 'Structure',
                        'asset' => 'Asset',
                        'service' => 'Service',
                        'governance' => 'Governance',
                        'generic' => 'Generic',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'missing_from_ldap' => 'Missing From LDAP',
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
            'index' => Pages\ListLdapDirectoryEntries::route('/'),
            'view' => Pages\ViewLdapDirectoryEntry::route('/{record}'),
        ];
    }
}
