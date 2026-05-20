<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;
use App\Models\Directory\LdapSchemaEntry;
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

class LdapSchemaEntryResource extends Resource
{
    protected static ?string $model = LdapSchemaEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?string $navigationLabel = 'Schema Browser';

    protected static ?string $modelLabel = 'LDAP Schema Entry';

    protected static ?string $pluralModelLabel = 'LDAP Schema Browser';

    protected static ?int $navigationSort = 60;

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
                Section::make('Schema Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('schema_type')
                            ->label('Schema Type')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'object_class' => 'info',
                                'attribute_type' => 'success',
                                'matching_rule' => 'warning',
                                'syntax' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('oid')->label('OID')->placeholder('N/A'),
                        TextEntry::make('name')->label('Primary Name')->placeholder('N/A'),
                        TextEntry::make('display_name')->label('Display Name')->placeholder('N/A'),
                        TextEntry::make('description')->label('Description')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Classification')
                    ->schema([
                        TextEntry::make('kind')->label('Kind')->badge()->placeholder('N/A'),
                        TextEntry::make('superior')->label('Superior')->placeholder('N/A'),
                        IconEntry::make('is_single_value')->label('Single Value')->boolean(),
                        IconEntry::make('is_operational')->label('Operational')->boolean(),
                        IconEntry::make('is_obsolete')->label('Obsolete')->boolean(),
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

                Section::make('Attribute Type Rules')
                    ->schema([
                        TextEntry::make('syntax_oid')->label('Syntax OID')->placeholder('N/A'),
                        TextEntry::make('equality_rule')->label('Equality Rule')->placeholder('N/A'),
                        TextEntry::make('ordering_rule')->label('Ordering Rule')->placeholder('N/A'),
                        TextEntry::make('substr_rule')->label('Substring Rule')->placeholder('N/A'),
                    ])
                    ->columns(4),

                Section::make('Names / ObjectClass Requirements')
                    ->schema([
                        TextEntry::make('names_text')
                            ->label('Names')
                            ->columnSpanFull(),

                        TextEntry::make('must_attributes_text')
                            ->label('MUST Attributes')
                            ->columnSpanFull(),

                        TextEntry::make('may_attributes_text')
                            ->label('MAY Attributes')
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

                Section::make('Raw Definition')
                    ->schema([
                        TextEntry::make('raw_definition')
                            ->label('Raw LDAP Schema Definition')
                            ->columnSpanFull()
                            ->placeholder('N/A'),
                    ]),

                Section::make('Extensions / Metadata')
                    ->schema([
                        TextEntry::make('extensions_json')
                            ->label('Extensions JSON')
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
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'ldap_connection_id',
                    'schema_type',
                    'oid',
                    'name',
                    'display_name',
                    'description',
                    'superior',
                    'kind',
                    'is_single_value',
                    'is_obsolete',
                    'is_operational',
                    'syntax_oid',
                    'equality_rule',
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

                TextColumn::make('schema_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'object_class' => 'info',
                        'attribute_type' => 'success',
                        'matching_rule' => 'warning',
                        'syntax' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(36)
                    ->placeholder('N/A'),

                TextColumn::make('oid')
                    ->label('OID')
                    ->searchable()
                    ->sortable()
                    ->limit(32)
                    ->placeholder('N/A'),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('superior')
                    ->label('Superior')
                    ->searchable()
                    ->sortable()
                    ->limit(28)
                    ->placeholder('N/A'),

                IconColumn::make('is_single_value')
                    ->label('Single')
                    ->boolean(),

                IconColumn::make('is_operational')
                    ->label('Operational')
                    ->boolean(),

                IconColumn::make('is_obsolete')
                    ->label('Obsolete')
                    ->boolean(),

                TextColumn::make('syntax_oid')
                    ->label('Syntax')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(32)
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
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('N/A'),
            ])
            ->filters([
                SelectFilter::make('schema_type')
                    ->label('Schema Type')
                    ->options([
                        'object_class' => 'Object Class',
                        'attribute_type' => 'Attribute Type',
                        'matching_rule' => 'Matching Rule',
                        'syntax' => 'Syntax',
                    ]),

                SelectFilter::make('kind')
                    ->label('Kind')
                    ->options([
                        'structural' => 'Structural ObjectClass',
                        'auxiliary' => 'Auxiliary ObjectClass',
                        'abstract' => 'Abstract ObjectClass',
                        'user_attribute' => 'User Attribute',
                        'operational_attribute' => 'Operational Attribute',
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
            'index' => Pages\ListLdapSchemaEntries::route('/'),
            'view' => Pages\ViewLdapSchemaEntry::route('/{record}'),
        ];
    }
}
