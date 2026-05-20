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

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'LDAP Transfer Center';

    protected static ?string $modelLabel = 'LDAP Transfer Center Operation';

    protected static ?string $pluralModelLabel = 'LDAP Transfer Center';

    protected static ?int $navigationSort = 45;


    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('1. Transfer Identity')
                    ->description('Pilih LDAP source dan target. Transfer hanya membuat preview LDIF dulu, belum menulis ke target LDAP.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Transfer Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Petra LDAP students to Tiny Test LDAP'),

                        Select::make('source_ldap_connection_id')
                            ->label('Source LDAP')
                            ->options(fn (): array => LdapConnection::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('target_ldap_connection_id')
                            ->label('Target LDAP')
                            ->options(fn (): array => LdapConnection::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),

                Section::make('2. Source Selection')
                    ->description('Ambil data langsung dari LDAP source, mirip LDIF Export. Tidak perlu upload CSV.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('transfer_scope')
                            ->label('Transfer What?')
                            ->options([
                                'full' => 'Full Base DN',
                                'ou' => 'Specific OU',
                                'cn' => 'Specific CN',
                                'uid' => 'Specific UID',
                                'custom_dn' => 'Custom DN',
                            ])
                            ->default('custom_dn')
                            ->required(),

                        TextInput::make('source_base_dn')
                            ->label('Source Base DN')
                            ->required()
                            ->placeholder('dc=petra,dc=ac,dc=id'),

                        TextInput::make('source_rdn_attribute')
                            ->label('RDN Attribute')
                            ->placeholder('ou / cn / uid'),

                        TextInput::make('source_rdn_value')
                            ->label('RDN Value')
                            ->placeholder('students / admin / test.queue001'),

                        Textarea::make('custom_source_dn')
                            ->label('Custom Source DN')
                            ->rows(2)
                            ->placeholder('ou=students,ou=people,dc=petra,dc=ac,dc=id')
                            ->columnSpanFull(),

                        Select::make('search_scope')
                            ->label('Search Scope')
                            ->options([
                                'base' => 'Base only',
                                'one' => 'One level',
                                'sub' => 'Full subtree',
                            ])
                            ->default('sub')
                            ->required(),

                        TextInput::make('filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->default('(objectClass=*)')
                            ->placeholder('(&(objectClass=inetOrgPerson)(!(uid=usr*)))'),

                        TextInput::make('attributes')
                            ->label('Attributes')
                            ->default('*')
                            ->placeholder('* or cn uid mail objectClass'),

                        TextInput::make('size_limit')
                            ->label('Size Limit')
                            ->numeric()
                            ->default(1000)
                            ->required(),

                        TextInput::make('page_size')
                            ->label('Page Size')
                            ->numeric()
                            ->default(500)
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),

                Section::make('3. Target Mapping')
                    ->description('Atur bagaimana DN source diubah menjadi DN target.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('target_parent_dn')
                            ->label('Target Parent DN / Target Base DN')
                            ->required()
                            ->rows(2)
                            ->placeholder('ou=transfer-target,dc=test,dc=local')
                            ->columnSpanFull(),

                        Select::make('target_dn_strategy')
                            ->label('Target DN Strategy')
                            ->options([
                                'preserve_tree' => 'Preserve tree',
                                'flatten' => 'Flatten to target parent',
                                'replace_base' => 'Replace base DN',
                            ])
                            ->default('preserve_tree')
                            ->required(),

                        Textarea::make('source_base_replacement')
                            ->label('Source Base Replacement')
                            ->rows(2)
                            ->placeholder('ou=people,dc=petra,dc=ac,dc=id'),

                        Textarea::make('target_base_replacement')
                            ->label('Target Base Replacement')
                            ->rows(2)
                            ->placeholder('ou=people,dc=test,dc=local'),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),

                Section::make('4. Safety Options')
                    ->description('Saat ini hanya preview. Apply transfer belum diaktifkan supaya aman.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('if_target_exists')
                            ->label('If Target Exists')
                            ->options([
                                'skip' => 'Skip existing target',
                                'fail' => 'Fail on conflict',
                                'merge' => 'Merge later',
                                'replace' => 'Replace later',
                            ])
                            ->default('skip')
                            ->required(),

                        TextInput::make('excluded_attributes')
                            ->label('Excluded Attributes')
                            ->default('userPassword entryUUID entryCSN createTimestamp creatorsName modifyTimestamp modifiersName structuralObjectClass')
                            ->placeholder('userPassword entryUUID entryCSN'),

                        Toggle::make('include_operational_attributes')
                            ->label('Include operational attributes')
                            ->default(false)
                            ->helperText('Biasanya tetap OFF karena banyak operational attributes bersifat read-only.'),

                        Toggle::make('preview_only')
                            ->label('Preview only')
                            ->default(true)
                            ->disabled()
                            ->dehydrated(true),

                        Toggle::make('safe_mode')
                            ->label('Safe mode')
                            ->default(true)
                            ->disabled()
                            ->dehydrated(true),

                        Toggle::make('destructive')
                            ->label('Destructive')
                            ->default(false)
                            ->disabled()
                            ->dehydrated(true),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
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

                Action::make('applyReal')
                    ->label('Apply Real')
                    ->icon('heroicon-o-bolt')
                    ->color('danger')
                    ->visible(fn (LdapCrudOperation $record): bool => $record->status === 'dry_run_success')
                    ->requiresConfirmation()
                    ->modalHeading('Apply LDAP change for real?')
                    ->modalDescription('This will run ldapmodify without dry-run. LDAP data WILL be changed. Continue only if the LDIF preview is correct.')
                    ->modalSubmitActionLabel('Yes, apply real LDAP change')
                    ->action(function (LdapCrudOperation $record): void {
                        $result = app(LdapCrudOperationService::class)->applyReal($record);

                        Notification::make()
                            ->title($result['ok'] ? 'LDAP apply success' : 'LDAP apply failed')
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
            'view' => Pages\ViewLdapCrudOperation::route('/{record}'),
        ];
    }
}
