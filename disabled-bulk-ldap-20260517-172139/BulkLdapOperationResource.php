<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;
use App\Models\BulkLdapOperation;
use App\Services\Ldap\BulkLdapOperationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;

class BulkLdapOperationResource extends Resource
{
    protected static ?string $model = BulkLdapOperation::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string | UnitEnum | null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'Bulk LDAP Operations';

    protected static ?string $modelLabel = 'Bulk LDAP Operation';

    protected static ?string $pluralModelLabel = 'Bulk LDAP Operations';

    protected static ?int $navigationSort = 75;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Source')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('operation_name')
                                    ->label('Operation Name')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('ldap_connection_name')
                                    ->label('LDAP Connection')
                                    ->required()
                                    ->default('Petra LDAP')
                                    ->helperText('Batch ini masih pakai nama connection. Setelah UI aman, kita sambungkan ke table LDAP Connections asli.'),
                            ]),

                        Forms\Components\TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->default('dc=petra,dc=ac,dc=id'),
                    ]),

                Forms\Components\Section::make('Target Filter')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('search_scope')
                                    ->label('Search Scope')
                                    ->options([
                                        'base' => 'Base DN only',
                                        'one' => 'One level',
                                        'subtree' => 'Full subtree',
                                    ])
                                    ->default('subtree')
                                    ->required(),

                                Forms\Components\TextInput::make('size_limit')
                                    ->label('Size Limit')
                                    ->numeric()
                                    ->default(100)
                                    ->required(),
                            ]),

                        Forms\Components\TextInput::make('ldap_filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->default('(objectClass=*)')
                            ->placeholder('(&(objectClass=inetOrgPerson)(uid=*))'),
                    ]),

                Forms\Components\Section::make('Operation')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('operation_type')
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

                                Forms\Components\Select::make('existing_value_behavior')
                                    ->label('If Attribute Exists')
                                    ->options([
                                        'skip' => 'Skip',
                                        'replace' => 'Replace',
                                        'append' => 'Append',
                                    ])
                                    ->default('skip')
                                    ->visible(fn (Forms\Get $get): bool => $get('operation_type') === 'add_attribute'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('objectclass_name')
                                    ->label('ObjectClass Name')
                                    ->placeholder('inetOrgPerson / posixAccount / customObjectClass')
                                    ->visible(fn (Forms\Get $get): bool => in_array($get('operation_type'), [
                                        'add_objectclass',
                                        'delete_objectclass',
                                    ], true)),

                                Forms\Components\TextInput::make('attribute_name')
                                    ->label('Attribute Name')
                                    ->placeholder('mail / telephoneNumber / description')
                                    ->visible(fn (Forms\Get $get): bool => in_array($get('operation_type'), [
                                        'add_attribute',
                                        'delete_attribute',
                                    ], true)),

                                Forms\Components\Textarea::make('attribute_value')
                                    ->label('Attribute Value')
                                    ->rows(3)
                                    ->visible(fn (Forms\Get $get): bool => $get('operation_type') === 'add_attribute'),

                                Forms\Components\TextInput::make('target_ou_dn')
                                    ->label('Target OU DN')
                                    ->placeholder('ou=alumni,ou=people,dc=petra,dc=ac,dc=id')
                                    ->visible(fn (Forms\Get $get): bool => $get('operation_type') === 'move_ou'),
                            ]),
                    ]),

                Forms\Components\Section::make('Preview Result')
                    ->schema([
                        Forms\Components\KeyValue::make('preview_result')
                            ->label('Preview Result')
                            ->disabled()
                            ->columnSpanFull(),

                        Forms\Components\KeyValue::make('execution_result')
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
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('operation_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ldap_connection_name')
                    ->label('LDAP')
                    ->searchable(),

                Tables\Columns\TextColumn::make('operation_type')
                    ->label('Operation')
                    ->badge(),

                Tables\Columns\TextColumn::make('search_scope')
                    ->label('Scope')
                    ->badge(),

                Tables\Columns\TextColumn::make('base_dn')
                    ->label('Base DN')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('ldap_filter')
                    ->label('Filter')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'previewed' => 'Previewed',
                        'executed_safe_mode' => 'Executed Safe Mode',
                    ]),

                Tables\Filters\SelectFilter::make('operation_type')
                    ->options([
                        'add_objectclass' => 'Add ObjectClass',
                        'delete_objectclass' => 'Delete ObjectClass',
                        'add_attribute' => 'Add Attribute',
                        'delete_attribute' => 'Delete Attribute',
                        'move_ou' => 'Move to OU',
                        'delete_entry' => 'Delete Entry',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(function (BulkLdapOperation $record): void {
                        $result = app(BulkLdapOperationService::class)->preview($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Preview ready' : 'Preview failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('execute')
                    ->label('Execute')
                    ->icon('heroicon-o-bolt')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Execute Bulk LDAP Operation?')
                    ->modalDescription('Batch ini masih safe mode. LDAP asli belum akan diubah.')
                    ->action(function (BulkLdapOperation $record): void {
                        $result = app(BulkLdapOperationService::class)->execute($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Execution finished' : 'Execution failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBulkLdapOperations::route('/'),
            'create' => Pages\CreateBulkLdapOperation::route('/create'),
            'view' => Pages\ViewBulkLdapOperation::route('/{record}'),
            'edit' => Pages\EditBulkLdapOperation::route('/{record}/edit'),
        ];
    }
}
