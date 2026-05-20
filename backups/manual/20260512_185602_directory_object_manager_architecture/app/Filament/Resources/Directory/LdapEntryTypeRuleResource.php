<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapEntryTypeRuleResource\Pages;
use App\Models\Directory\LdapEntryTypeRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class LdapEntryTypeRuleResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = '1. Directory Management';
    protected static ?int $navigationSort = 90;

    protected static ?string $model = LdapEntryTypeRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Entry Type Registry';

    protected static ?string $modelLabel = 'LDAP Entry Type Rule';

    protected static ?string $pluralModelLabel = 'LDAP Entry Type Registry';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rule Identity')
                    ->schema([
                        TextInput::make('rule_key')
                            ->label('Rule Key')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Example: user_inetorgperson, group_groupofnames, custom_student_entry'),

                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('entry_type')
                            ->label('Entry Type')
                            ->required()
                            ->options([
                                'user' => 'User',
                                'group' => 'Group',
                                'role' => 'Role',
                                'application' => 'Application',
                                'unit_ou' => 'Unit / OU',
                                'device' => 'Device',
                                'service_account' => 'Service Account',
                                'policy' => 'Policy',
                                'schema' => 'Schema',
                                'generic_entry' => 'Generic Entry',
                                'custom' => 'Custom',
                            ])
                            ->searchable(),

                        Select::make('entry_category')
                            ->label('Entry Category')
                            ->options([
                                'identity' => 'Identity',
                                'authorization' => 'Authorization',
                                'application_access' => 'Application Access',
                                'structure' => 'Structure',
                                'asset' => 'Asset',
                                'service' => 'Service',
                                'governance' => 'Governance',
                                'schema' => 'Schema',
                                'generic' => 'Generic',
                                'custom' => 'Custom',
                            ])
                            ->searchable(),

                        TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->default(100)
                            ->required()
                            ->helperText('Lower number means higher priority.'),

                        Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->default(true),

                        Toggle::make('is_system')
                            ->label('System Rule')
                            ->default(false),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Matching Rules')
                    ->schema([
                        Repeater::make('required_object_classes')
                            ->label('Required ObjectClasses')
                            ->schema([
                                TextInput::make('value')
                                    ->label('objectClass')
                                    ->required(),
                            ])
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        Repeater::make('optional_object_classes')
                            ->label('Optional ObjectClasses')
                            ->schema([
                                TextInput::make('value')
                                    ->label('objectClass')
                                    ->required(),
                            ])
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        Repeater::make('dn_contains_patterns')
                            ->label('DN Contains Patterns')
                            ->schema([
                                TextInput::make('value')
                                    ->label('Pattern')
                                    ->required(),
                            ])
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        Repeater::make('dn_starts_with_patterns')
                            ->label('DN Starts With Patterns')
                            ->schema([
                                TextInput::make('value')
                                    ->label('Pattern')
                                    ->required(),
                            ])
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        Repeater::make('rdn_attributes')
                            ->label('RDN Attributes')
                            ->schema([
                                TextInput::make('value')
                                    ->label('RDN Attribute')
                                    ->required(),
                            ])
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),

                Section::make('Attribute Mapping')
                    ->schema([
                        TextInput::make('identifier_attribute')
                            ->label('Identifier Attribute')
                            ->placeholder('uid / cn / employeeNumber / mail'),

                        TextInput::make('display_attribute')
                            ->label('Display Attribute')
                            ->placeholder('cn / displayName / ou'),

                        TextInput::make('email_attribute')
                            ->label('Email Attribute')
                            ->placeholder('mail / userPrincipalName'),

                        TextInput::make('uuid_attribute')
                            ->label('UUID Attribute')
                            ->placeholder('entryUUID / objectGUID'),

                        TextInput::make('membership_attribute')
                            ->label('Membership Attribute')
                            ->placeholder('memberOf / member / uniqueMember / memberUid'),

                        TextInput::make('filament_icon')
                            ->label('Filament Icon')
                            ->placeholder('heroicon-o-user'),

                        TextInput::make('badge_color')
                            ->label('Badge Color')
                            ->placeholder('info / success / warning / danger / gray'),
                    ])
                    ->columns(3),

                Section::make('Metadata')
                    ->schema([
                        KeyValue::make('metadata')
                            ->label('Metadata')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rule Identity')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('rule_key')->label('Rule Key'),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('entry_type')->label('Entry Type')->badge(),
                        TextEntry::make('entry_category')->label('Category')->badge()->placeholder('N/A'),
                        TextEntry::make('priority')->label('Priority'),
                        IconEntry::make('is_enabled')->label('Enabled')->boolean(),
                        IconEntry::make('is_system')->label('System')->boolean(),
                        TextEntry::make('description')->label('Description')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Matching Rules')
                    ->schema([
                        TextEntry::make('required_object_classes_text')->label('Required ObjectClasses')->columnSpanFull(),
                        TextEntry::make('optional_object_classes_text')->label('Optional ObjectClasses')->columnSpanFull(),
                        TextEntry::make('dn_contains_patterns_text')->label('DN Contains Patterns')->columnSpanFull(),
                        TextEntry::make('dn_starts_with_patterns_text')->label('DN Starts With Patterns')->columnSpanFull(),
                        TextEntry::make('rdn_attributes_text')->label('RDN Attributes')->columnSpanFull(),
                    ]),

                Section::make('Attribute Mapping')
                    ->schema([
                        TextEntry::make('identifier_attribute')->label('Identifier Attribute')->placeholder('N/A'),
                        TextEntry::make('display_attribute')->label('Display Attribute')->placeholder('N/A'),
                        TextEntry::make('email_attribute')->label('Email Attribute')->placeholder('N/A'),
                        TextEntry::make('uuid_attribute')->label('UUID Attribute')->placeholder('N/A'),
                        TextEntry::make('membership_attribute')->label('Membership Attribute')->placeholder('N/A'),
                        TextEntry::make('filament_icon')->label('Icon')->placeholder('N/A'),
                        TextEntry::make('badge_color')->label('Badge Color')->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('metadata_json')
                            ->label('Metadata JSON')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([

                \Filament\Actions\DeleteBulkAction::make()
                    ->label('Delete Selected'),
                ]),
            ])
            ->defaultSort('priority', 'asc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'uuid',
                    'rule_key',
                    'name',
                    'entry_type',
                    'entry_category',
                    'identifier_attribute',
                    'display_attribute',
                    'priority',
                    'is_enabled',
                    'is_system',
                    'created_at',
                    'updated_at',
                ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Rule')
                    ->searchable()
                    ->sortable()
                    ->limit(34),

                TextColumn::make('rule_key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->limit(32),

                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('entry_category')
                    ->label('Category')
                    ->badge()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('identifier_attribute')
                    ->label('Identifier')
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('display_attribute')
                    ->label('Display')
                    ->sortable()
                    ->placeholder('N/A'),

                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_system')
                    ->label('System')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([

                \Filament\Actions\Action::make('syncRootOuRegistry')
                    ->label('Sync Root OU Registry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (): void {
                        try {
                            \Artisan::call('iam:sync-root-ou-entry-types');

                            \Filament\Notifications\Notification::make()
                                ->title('Root OU registry synced')
                                ->body('OU dari root LDAP sudah disinkronkan ke Entry Type Registry. Refresh browser untuk melihat navbar.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Root OU registry sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
                        'schema' => 'Schema',
                        'generic_entry' => 'Generic Entry',
                        'custom' => 'Custom',
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
                        'schema' => 'Schema',
                        'generic' => 'Generic',
                        'custom' => 'Custom',
                    ]),

                SelectFilter::make('is_enabled')
                    ->label('Enabled')
                    ->options([
                        '1' => 'Enabled',
                        '0' => 'Disabled',
                    ]),

                SelectFilter::make('is_system')
                    ->label('System Rule')
                    ->options([
                        '1' => 'System',
                        '0' => 'Custom',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (LdapEntryTypeRule $record): bool => ! $record->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapEntryTypeRules::route('/'),
            'create' => Pages\CreateLdapEntryTypeRule::route('/create'),
            'view' => Pages\ViewLdapEntryTypeRule::route('/{record}'),
            'edit' => Pages\EditLdapEntryTypeRule::route('/{record}/edit'),
        ];
    }
}
