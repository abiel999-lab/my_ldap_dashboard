<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapCrudOperation;
use App\Services\Operations\LdapCrudOperationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class LdapCrudOperationResource extends Resource
{
    protected static ?string $model = LdapCrudOperation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'LDAP CRUD Operations';

    protected static ?string $modelLabel = 'LDAP CRUD Operation';

    protected static ?string $pluralModelLabel = 'LDAP CRUD Operations';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Operation')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Create demo user dry-run'),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->required()
                            ->options(fn (): array => LdapConnection::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),

                        Select::make('operation_type')
                            ->label('Operation Type')
                            ->required()
                            ->options([
                                'create_entry' => 'Create Entry',
                                'modify_entry' => 'Modify Entry',
                                'delete_entry' => 'Delete Entry',
                                'rename_dn' => 'Rename DN',
                                'move_dn' => 'Move DN',
                            ])
                            ->live(),

                        Textarea::make('target_dn')
                            ->label('Target DN')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('uid=demo.user3,dc=petra,dc=ac,dc=id'),

                        TextInput::make('new_rdn')
                            ->label('New RDN')
                            ->placeholder('uid=demo.user3.new'),

                        Textarea::make('parent_dn')
                            ->label('New Parent DN')
                            ->rows(2)
                            ->placeholder('ou=people,dc=petra,dc=ac,dc=id'),

                        Toggle::make('safe_mode')
                            ->label('Safe Mode')
                            ->default(true)
                            ->disabled(),

                        Toggle::make('dry_run')
                            ->label('Dry Run')
                            ->default(true)
                            ->disabled(),

                        Toggle::make('destructive')
                            ->label('Destructive')
                            ->helperText('Only enable for delete/apply steps later. Preview and dry-run still do not change LDAP.'),

                        Toggle::make('approval_required')
                            ->label('Approval Required')
                            ->default(true),
                    ])
                    ->columns(3),

                Section::make('Create Entry Data')
                    ->schema([
                        Repeater::make('object_classes')
                            ->label('Object Classes')
                            ->simple(
                                TextInput::make('value')
                                    ->required()
                                    ->placeholder('inetOrgPerson')
                            )
                            ->defaultItems(0)
                            ->columnSpanFull(),

                        KeyValue::make('attributes')
                            ->label('Attributes')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->columnSpanFull()
                            ->helperText('Example: uid=demo.user3, cn=Demo User Three, sn=Three, mail=demo.user3@petra.ac.id'),
                    ]),

                Section::make('Modify Entry Changes')
                    ->schema([
                        Repeater::make('attribute_changes')
                            ->label('Attribute Changes')
                            ->schema([
                                Select::make('action')
                                    ->label('Action')
                                    ->required()
                                    ->options([
                                        'add' => 'Add',
                                        'replace' => 'Replace',
                                        'delete' => 'Delete',
                                    ])
                                    ->default('replace'),

                                TextInput::make('attribute')
                                    ->label('Attribute')
                                    ->required()
                                    ->placeholder('mail'),

                                Textarea::make('values')
                                    ->label('Values')
                                    ->rows(2)
                                    ->helperText('For now write one value. Multi-value support will be improved later.'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),

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
                Section::make('Operation')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('operation_type')->label('Type')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'draft' => 'gray',
                                'previewed', 'dry_run_success' => 'success',
                                'preview_failed', 'dry_run_failed', 'failed' => 'danger',
                                'queued', 'running' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('target_dn')->label('Target DN')->columnSpanFull()->placeholder('N/A'),
                        TextEntry::make('new_rdn')->label('New RDN')->placeholder('N/A'),
                        TextEntry::make('parent_dn')->label('New Parent DN')->columnSpanFull()->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('dry_run')->label('Dry Run')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                        IconEntry::make('approval_required')->label('Approval Required')->boolean(),
                    ])
                    ->columns(4),

                Section::make('Preview')
                    ->schema([
                        TextEntry::make('ldif_preview')
                            ->label('LDIF Preview')
                            ->placeholder('No LDIF preview yet.')
                            ->columnSpanFull(),

                        TextEntry::make('command_preview')
                            ->label('Command Preview')
                            ->placeholder('No command preview yet.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Validation / Output')
                    ->schema([
                        TextEntry::make('message')->label('Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('error_message')->label('Error Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('validation_errors_json')->label('Validation Errors JSON')->columnSpanFull(),
                        TextEntry::make('attributes_json')->label('Attributes JSON')->columnSpanFull(),
                        TextEntry::make('attribute_changes_json')->label('Attribute Changes JSON')->columnSpanFull(),
                    ]),

                Section::make('Links / Timeline')
                    ->schema([
                        TextEntry::make('preview_command_execution_id')->label('Preview Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('apply_command_execution_id')->label('Apply Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('previewed_at')->label('Previewed At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('applied_at')->label('Applied At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('failed_at')->label('Failed At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('operation_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'previewed', 'dry_run_success' => 'success',
                        'preview_failed', 'dry_run_failed', 'failed' => 'danger',
                        'queued', 'running' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(24),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->searchable()
                    ->limit(56),

                IconColumn::make('safe_mode')
                    ->label('Safe')
                    ->boolean(),

                IconColumn::make('dry_run')
                    ->label('Dry')
                    ->boolean(),

                IconColumn::make('destructive')
                    ->label('Danger')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('operation_type')
                    ->label('Type')
                    ->options([
                        'create_entry' => 'Create Entry',
                        'modify_entry' => 'Modify Entry',
                        'delete_entry' => 'Delete Entry',
                        'rename_dn' => 'Rename DN',
                        'move_dn' => 'Move DN',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'previewed' => 'Previewed',
                        'preview_failed' => 'Preview Failed',
                        'dry_run_success' => 'Dry-run Success',
                        'dry_run_failed' => 'Dry-run Failed',
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'failed' => 'Failed',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Generate LDAP CRUD preview?')
                    ->modalDescription('This only generates LDIF preview and validation. LDAP data will not be changed.')
                    ->action(function (LdapCrudOperation $record): void {
                        $result = app(LdapCrudOperationService::class)->preview($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Preview generated' : 'Preview has validation errors')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                Action::make('dryRun')
                    ->label('Dry Run')
                    ->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Run LDAP CRUD dry-run?')
                    ->modalDescription('This runs ldapmodify with -n. LDAP data will not be changed.')
                    ->action(function (LdapCrudOperation $record): void {
                        $result = app(LdapCrudOperationService::class)->dryRun($record);

                        Notification::make()
                            ->title($result['ok'] ? 'Dry-run success' : 'Dry-run failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
            ]);
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
