<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapCrudOperation;
use App\Services\Operations\LdapCrudOperationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class LdapCrudOperationResource extends Resource
{
    protected static ?string $model = LdapCrudOperation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'LDAP Bulk Operations';

    protected static ?string $modelLabel = 'LDAP Bulk Operation';

    protected static ?string $pluralModelLabel = 'LDAP Bulk Operations';

    protected static ?int $navigationSort = 44;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('1. Source')
                    ->description('Pilih LDAP yang akan diproses. Jika salah memilih LDAP, preview/execute berikutnya akan dianggap error.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Operation Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Bulk add telephoneNumber to selected users'),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => LdapConnection::query()
                                ->where('is_active', true)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('target_mode')
                            ->label('Target Mode')
                            ->options([
                                'base_dn' => 'Base DN + LDAP Filter',
                                'custom_dn' => 'Custom Target DN',
                                'rdn_filter' => 'RDN Attribute + Value',
                            ])
                            ->default('base_dn')
                            ->required()
                            ->live(),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->default('dc=petra,dc=ac,dc=id')
                            ->visible(fn ($get): bool => $get('target_mode') !== 'custom_dn')
                            ->columnSpanFull(),

                        TextInput::make('custom_target_dn')
                            ->label('Custom Target DN')
                            ->placeholder('ou=alumni,ou=people,dc=petra,dc=ac,dc=id')
                            ->visible(fn ($get): bool => $get('target_mode') === 'custom_dn')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('2. Target Filter')
                    ->description('Target bisa banyak DN, seperti form export. Gunakan LDAP filter untuk memilih user, cn, ou, atau objectClass tertentu.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('search_scope')
                                    ->label('Search Scope')
                                    ->options([
                                        'base' => 'Base DN only',
                                        'one' => 'One level',
                                        'subtree' => 'Full subtree',
                                    ])
                                    ->default('subtree')
                                    ->required(),

                                TextInput::make('size_limit')
                                    ->label('Size Limit')
                                    ->numeric()
                                    ->default(100)
                                    ->required(),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Select::make('rdn_attribute')
                                    ->label('RDN Attribute')
                                    ->options([
                                        'uid' => 'uid',
                                        'cn' => 'cn',
                                        'ou' => 'ou',
                                        'dc' => 'dc',
                                    ])
                                    ->searchable()
                                    ->visible(fn ($get): bool => $get('target_mode') === 'rdn_filter'),

                                TextInput::make('rdn_value')
                                    ->label('RDN Value')
                                    ->placeholder('usr000043 / alumni / admin')
                                    ->visible(fn ($get): bool => $get('target_mode') === 'rdn_filter'),
                            ]),

                        TextInput::make('ldap_filter')
                            ->label('LDAP Filter')
                            ->default('(objectClass=*)')
                            ->required()
                            ->placeholder('(&(objectClass=inetOrgPerson)(uid=*))')
                            ->columnSpanFull(),
                    ]),

                Section::make('3. Bulk Operation')
                    ->description('Operasi massal. Entry yang tidak memenuhi syarat akan di-skip, bukan dipaksa.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('operation_kind')
                                    ->label('Operation Type')
                                    ->options([
                                        'add_objectclass' => 'Add ObjectClass',
                                        'delete_objectclass' => 'Delete ObjectClass',
                                        'add_attribute' => 'Add Attribute',
                                        'delete_attribute' => 'Delete Attribute',
                                        'move_ou' => 'Move to OU',
                                        'delete_entry' => 'Delete Entry',
                                    ])
                                    ->default('add_attribute')
                                    ->required()
                                    ->live(),

                                Select::make('existing_value_behavior')
                                    ->label('If Attribute Exists')
                                    ->options([
                                        'skip' => 'Skip',
                                        'replace' => 'Replace',
                                        'append' => 'Append',
                                    ])
                                    ->default('skip')
                                    ->visible(fn ($get): bool => $get('operation_kind') === 'add_attribute'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('objectclass_name')
                                    ->label('ObjectClass Name')
                                    ->placeholder('inetOrgPerson / posixAccount / customObjectClass')
                                    ->visible(fn ($get): bool => in_array($get('operation_kind'), [
                                        'add_objectclass',
                                        'delete_objectclass',
                                    ], true)),

                                TextInput::make('attribute_name')
                                    ->label('Attribute Name')
                                    ->placeholder('mail / telephoneNumber / description')
                                    ->visible(fn ($get): bool => in_array($get('operation_kind'), [
                                        'add_attribute',
                                        'delete_attribute',
                                    ], true)),

                                Textarea::make('attribute_value')
                                    ->label('Attribute Value')
                                    ->rows(4)
                                    ->placeholder('Value baru. Untuk multi-value nanti bisa dipisah per baris.')
                                    ->visible(fn ($get): bool => $get('operation_kind') === 'add_attribute')
                                    ->columnSpanFull(),

                                TextInput::make('target_ou_dn')
                                    ->label('Target OU DN')
                                    ->placeholder('ou=alumni,ou=people,dc=petra,dc=ac,dc=id')
                                    ->visible(fn ($get): bool => $get('operation_kind') === 'move_ou')
                                    ->columnSpanFull(),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Toggle::make('skip_if_invalid')
                                    ->label('Skip invalid entries')
                                    ->default(true)
                                    ->helperText('Jika DN tidak memenuhi syarat objectClass/attribute, sistem skip.'),

                                Select::make('missing_objectclass_behavior')
                                    ->label('If required objectClass missing')
                                    ->options([
                                        'skip' => 'Skip entry',
                                        'assign_objectclass_first' => 'Assign objectClass first',
                                    ])
                                    ->default('skip')
                                    ->visible(fn ($get): bool => $get('operation_kind') === 'add_attribute'),

                                TextInput::make('queue_threshold')
                                    ->label('Queue Threshold')
                                    ->numeric()
                                    ->default(200)
                                    ->helperText('Jika hasil preview melebihi angka ini, sistem tandai untuk Laravel Queue Job.'),

                                Toggle::make('require_preview')
                                    ->label('Require preview before execute')
                                    ->default(true)
                                    ->helperText('Aman: execute harus setelah preview.'),
                            ]),
                    ]),

                Section::make('4. Preview / Execution Result')
                    ->description('Batch pertama ini safe mode. LDAP asli belum diubah.')
                    ->schema([
                        KeyValue::make('preview_result')
                            ->label('Preview Result')
                            ->disabled()
                            ->columnSpanFull(),

                        KeyValue::make('execution_result')
                            ->label('Execution Result')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('ldapConnection.name')
                    ->label('LDAP')
                    ->badge()
                    ->searchable(),

                TextColumn::make('operation_kind')
                    ->label('Operation')
                    ->badge()
                    ->sortable(),

                TextColumn::make('search_scope')
                    ->label('Scope')
                    ->badge(),

                TextColumn::make('base_dn')
                    ->label('Base DN')
                    ->limit(45)
                    ->searchable(),

                TextColumn::make('ldap_filter')
                    ->label('Filter')
                    ->limit(45)
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'previewed' => 'Previewed',
                        'executed_safe_mode' => 'Executed Safe Mode',
                        'success' => 'Success',
                        'failed' => 'Failed',
                    ]),

                SelectFilter::make('operation_kind')
                    ->options([
                        'add_objectclass' => 'Add ObjectClass',
                        'delete_objectclass' => 'Delete ObjectClass',
                        'add_attribute' => 'Add Attribute',
                        'delete_attribute' => 'Delete Attribute',
                        'move_ou' => 'Move to OU',
                        'delete_entry' => 'Delete Entry',
                    ]),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(function (LdapCrudOperation $record): void {
                        $result = app(LdapCrudOperationService::class)->preview($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Preview ready' : 'Preview failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                Action::make('execute')
                    ->label('Execute')
                    ->icon('heroicon-o-bolt')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Execute LDAP Bulk Operation?')
                    ->modalDescription('Batch ini masih safe mode. LDAP asli belum akan diubah.')
                    ->action(function (LdapCrudOperation $record): void {
                        $result = app(LdapCrudOperationService::class)->execute($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Execution finished' : 'Execution failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapCrudOperations::route('/'),
            'create' => Pages\CreateLdapCrudOperation::route('/create'),
            'view' => Pages\ViewLdapCrudOperation::route('/{record}'),
            'edit' => Pages\EditLdapCrudOperation::route('/{record}/edit'),
        ];
    }
}
