<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapConnectionResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Services\Ldap\LdapConnectionHealthService;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use UnitEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LdapConnectionResource extends Resource
{
    protected static ?string $model = LdapConnection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|UnitEnum|null $navigationGroup = '1. Directory Management';

    protected static ?string $navigationLabel = 'LDAP Connections';

    protected static ?string $modelLabel = 'LDAP Connection';

    protected static ?string $pluralModelLabel = 'LDAP Connections';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection Identity')
                    ->description('Basic identity and environment label for this LDAP server.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Connection Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Production LDAP'),

                        TextInput::make('environment_label')
                            ->label('Environment')
                            ->required()
                            ->maxLength(255)
                            ->default('local')
                            ->placeholder('production, staging, testing, local'),
                    ])
                    ->columns(2),

                Section::make('LDAP Server')
                    ->description('Main LDAP server endpoint and bind configuration.')
                    ->schema([
                        TextInput::make('host')
                            ->label('Host')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('ldap.example.ac.id'),

                        TextInput::make('port')
                            ->label('Port')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(389),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('dc=petra,dc=ac,dc=id')
                            ->columnSpanFull(),

                        TextInput::make('bind_dn')
                            ->label('Bind DN')
                            ->maxLength(255)
                            ->placeholder('cn=admin,dc=petra,dc=ac,dc=id')
                            ->columnSpanFull(),

                        TextInput::make('bind_password')
                            ->label('Bind Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave empty when editing if you do not want to change the stored bind password.')
                            ->columnSpanFull(),

                        Toggle::make('use_ssl')
                            ->label('Use SSL'),

                        Toggle::make('use_tls')
                            ->label('Use TLS'),

                        TextInput::make('timeout')
                            ->label('Timeout Seconds')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default(5),
                    ])
                    ->columns(3),

                Section::make('Connection Mode')
                    ->description('Controls whether this connection is active, default, or read-only.')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Toggle::make('is_default')
                            ->label('Default Connection')
                            ->helperText('Only one LDAP connection should be the default.'),

                        Toggle::make('is_read_only')
                            ->label('Read-only Mode')
                            ->helperText('Read-only connections should not be used for destructive LDAP operations.'),
                    ])
                    ->columns(3),

                Section::make('Dynamic LDAP Mapping')
                    ->description('Do not hardcode LDAP structure. Configure per connection.')
                    ->schema([
                        TextInput::make('user_base_dn')
                            ->label('User Base DN')
                            ->maxLength(255)
                            ->placeholder('ou=people,dc=petra,dc=ac,dc=id'),

                        TextInput::make('group_base_dn')
                            ->label('Group Base DN')
                            ->maxLength(255)
                            ->placeholder('ou=groups,dc=petra,dc=ac,dc=id'),

                        TextInput::make('user_identifier_attribute')
                            ->label('User Identifier Attribute')
                            ->required()
                            ->default('uid')
                            ->maxLength(255),

                        TextInput::make('user_display_attribute')
                            ->label('User Display Attribute')
                            ->required()
                            ->default('cn')
                            ->maxLength(255),

                        TextInput::make('user_email_attribute')
                            ->label('User Email Attribute')
                            ->required()
                            ->default('mail')
                            ->maxLength(255),

                        TextInput::make('group_member_attribute')
                            ->label('Group Member Attribute')
                            ->required()
                            ->default('member')
                            ->maxLength(255),

                        TextInput::make('uuid_attribute')
                            ->label('UUID Attribute')
                            ->required()
                            ->default('entryUUID')
                            ->maxLength(255),

                        KeyValue::make('attribute_mapping')
                            ->label('Additional Attribute Mapping')
                            ->keyLabel('Application Field')
                            ->valueLabel('LDAP Attribute')
                            ->columnSpanFull(),

                        Textarea::make('metadata')
                            ->label('Metadata JSON Notes')
                            ->helperText('Optional notes for future automation. Keep secrets out of this field.')
                            ->rows(4)
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection Summary')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),

                        TextEntry::make('environment_label')
                            ->label('Environment')
                            ->badge(),

                        TextEntry::make('host')
                            ->label('Host'),

                        TextEntry::make('port')
                            ->label('Port'),

                        TextEntry::make('base_dn')
                            ->label('Base DN')
                            ->columnSpanFull(),

                        TextEntry::make('bind_dn')
                            ->label('Bind DN')
                            ->placeholder('Not configured')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Mode')
                    ->schema([
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),

                        IconEntry::make('is_default')
                            ->label('Default')
                            ->boolean(),

                        IconEntry::make('is_read_only')
                            ->label('Read-only')
                            ->boolean(),

                        IconEntry::make('use_ssl')
                            ->label('SSL')
                            ->boolean(),

                        IconEntry::make('use_tls')
                            ->label('TLS')
                            ->boolean(),

                        TextEntry::make('timeout')
                            ->label('Timeout')
                            ->suffix(' second(s)'),
                    ])
                    ->columns(3),

                Section::make('Dynamic Mapping')
                    ->schema([
                        TextEntry::make('user_base_dn')
                            ->label('User Base DN')
                            ->placeholder('Not configured'),

                        TextEntry::make('group_base_dn')
                            ->label('Group Base DN')
                            ->placeholder('Not configured'),

                        TextEntry::make('user_identifier_attribute')
                            ->label('User Identifier'),

                        TextEntry::make('user_display_attribute')
                            ->label('Display Attribute'),

                        TextEntry::make('user_email_attribute')
                            ->label('Email Attribute'),

                        TextEntry::make('group_member_attribute')
                            ->label('Group Member Attribute'),

                        TextEntry::make('uuid_attribute')
                            ->label('UUID Attribute'),
                    ])
                    ->columns(2),

                Section::make('Health')
                    ->schema([
                        TextEntry::make('last_health_status')
                            ->label('Last Status')
                            ->badge()
                            ->placeholder('Not checked yet'),

                        TextEntry::make('last_health_checked_at')
                            ->label('Last Checked')
                            ->dateTime()
                            ->placeholder('Never'),

                        TextEntry::make('last_health_message')
                            ->label('Message')
                            ->placeholder('No health message')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('id'))
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('environment_label')
                    ->label('Environment')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('host')
                    ->label('Host')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('port')
                    ->label('Port')
                    ->sortable(),

                TextColumn::make('base_dn')
                    ->label('Base DN')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (LdapConnection $record): string => $record->base_dn),

                TextColumn::make('security_mode')
                    ->label('Security')
                    ->badge(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_read_only')
                    ->label('Read-only')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_health_status')
                    ->label('Health')
                    ->badge()
                    ->placeholder('Not checked')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('testConnection')
                    ->label('Test Connection')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Test LDAP connection?')
                    ->modalDescription('This will connect and bind to the LDAP server, then update the stored health status. It will not modify LDAP data.')
                    ->action(function (LdapConnection $record): void {
                        $before = $record->toArray();

                        $result = app(LdapConnectionHealthService::class)->check($record);

                        $record->forceFill([
                            'last_health_checked_at' => now(),
                            'last_health_status' => $result['status'],
                            'last_health_message' => $result['message'].' Duration: '.$result['duration_ms'].'ms.',
                        ])->save();

                        app(AuditLogger::class)->logModelAction(
                            module: 'directory.ldap_connections',
                            action: 'test_connection',
                            status: $result['ok'] ? 'success' : 'failed',
                            target: $record,
                            before: $before,
                            after: $record->fresh()?->toArray(),
                            errorMessage: $result['ok'] ? null : $result['message'],
                            durationMs: $result['duration_ms'],
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'LDAP connection healthy' : 'LDAP connection failed')
                            ->body($result['message'])
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('setDefault')
                    ->label('Set Default')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (LdapConnection $record): bool => ! $record->is_default)
                    ->requiresConfirmation()
                    ->modalHeading('Set as default LDAP connection?')
                    ->modalDescription('This will unset any previous default LDAP connection.')
                    ->action(function (LdapConnection $record): void {
                        $before = $record->toArray();

                        LdapConnection::query()
                            ->whereKeyNot($record->getKey())
                            ->where('is_default', true)
                            ->update(['is_default' => false]);

                        $record->forceFill([
                            'is_default' => true,
                            'is_active' => true,
                        ])->save();

                        app(AuditLogger::class)->logModelAction(
                            module: 'directory.ldap_connections',
                            action: 'set_default',
                            status: 'success',
                            target: $record,
                            before: $before,
                            after: $record->fresh()?->toArray(),
                        );

                        Notification::make()
                            ->title('Default LDAP connection updated')
                            ->body($record->name . ' is now the default LDAP connection.')
                            ->success()
                            ->send();
                    }),

                Action::make('toggleActive')
                    ->label(fn (LdapConnection $record): string => $record->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (LdapConnection $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (LdapConnection $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (LdapConnection $record): string => $record->is_active ? 'Disable LDAP connection?' : 'Enable LDAP connection?')
                    ->modalDescription('This only changes application configuration. It does not modify the LDAP server.')
                    ->action(function (LdapConnection $record): void {
                        $before = $record->toArray();
                        $newStatus = ! $record->is_active;

                        $record->forceFill([
                            'is_active' => $newStatus,
                        ])->save();

                        app(AuditLogger::class)->logModelAction(
                            module: 'directory.ldap_connections',
                            action: $newStatus ? 'enable' : 'disable',
                            status: 'success',
                            target: $record,
                            before: $before,
                            after: $record->fresh()?->toArray(),
                        );

                        Notification::make()
                            ->title($record->is_active ? 'LDAP connection enabled' : 'LDAP connection disabled')
                            ->body($record->name . ' has been updated.')
                            ->success()
                            ->send();
                    }),

                Action::make('clearHealth')
                    ->label('Clear Health')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (LdapConnection $record): bool => filled($record->last_health_status) || filled($record->last_health_message))
                    ->requiresConfirmation()
                    ->modalHeading('Clear health status?')
                    ->modalDescription('This only clears the last stored health status. It does not test the LDAP connection.')
                    ->action(function (LdapConnection $record): void {
                        $before = $record->toArray();

                        $record->forceFill([
                            'last_health_checked_at' => null,
                            'last_health_status' => null,
                            'last_health_message' => null,
                        ])->save();

                        app(AuditLogger::class)->logModelAction(
                            module: 'directory.ldap_connections',
                            action: 'clear_health',
                            status: 'success',
                            target: $record,
                            before: $before,
                            after: $record->fresh()?->toArray(),
                        );

                        Notification::make()
                            ->title('Health status cleared')
                            ->body($record->name . ' health status has been cleared.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'environment_label',
            'host',
            'base_dn',
            'bind_dn',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapConnections::route('/'),
            'create' => Pages\CreateLdapConnection::route('/create'),
            'view' => Pages\ViewLdapConnection::route('/{record}'),
            'edit' => Pages\EditLdapConnection::route('/{record}/edit'),
        ];
    }
}
