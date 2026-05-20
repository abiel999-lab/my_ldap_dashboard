<?php

namespace App\Filament\Resources\Directory;

use App\Filament\Resources\Directory\LdapServerResource\Pages;
use App\Models\Directory\LdapServer;
use App\Services\Directory\LdapServerProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;
use BackedEnum;

class LdapServerResource extends Resource
{
    protected static ?string $model = LdapServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'LDAP Servers';

    protected static ?string $modelLabel = 'LDAP Server';

    protected static ?string $pluralModelLabel = 'LDAP Servers';

    protected static string|UnitEnum|null $navigationGroup = '1. DIRECTORY MANAGEMENT';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(static::formSchema());
    }

    public static function formSchema(): array
    {
        return [
            Section::make('Server Identity')
                ->description('Konfigurasi dasar LDAP server baru.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('Server Name')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (filled($state)) {
                                    $set('slug', Str::slug($state));
                                    $set('container_name', 'openldap-'.Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Dipakai untuk nama container/kubernetes object.'),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('organization')
                            ->label('Organization')
                            ->default('Petra Christian University'),

                        TextInput::make('domain')
                            ->label('LDAP Domain')
                            ->default('test.local')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (filled($state)) {
                                    $base = collect(explode('.', strtolower($state)))
                                        ->filter()
                                        ->map(fn ($part) => 'dc='.preg_replace('/[^a-z0-9-]/', '', $part))
                                        ->implode(',');

                                    $set('base_dn', $base ?: 'dc=test,dc=local');
                                    $set('admin_dn', 'cn=admin,'.($base ?: 'dc=test,dc=local'));
                                }
                            }),
                    ]),
                ])
                ->columns(1),

            Section::make('LDAP Access')
                ->description('Credential dan endpoint LDAP.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('base_dn')
                            ->required()
                            ->default('dc=test,dc=local'),

                        TextInput::make('admin_rdn')
                            ->required()
                            ->default('cn=admin')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                $set('admin_dn', ($state ?: 'cn=admin').','.($get('base_dn') ?: 'dc=test,dc=local'));
                            }),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('admin_dn')
                            ->required()
                            ->default('cn=admin,dc=test,dc=local'),

                        TextInput::make('admin_password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->default('SeongJinWoo999!'),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('host')
                            ->required()
                            ->default('127.0.0.1'),

                        TextInput::make('ldap_port')
                            ->numeric()
                            ->required()
                            ->default(389),

                        TextInput::make('ldaps_port')
                            ->numeric()
                            ->nullable(),
                    ]),
                ])
                ->columns(1),

            Section::make('Provisioning')
                ->description('Sistem generate command/manifest dulu, deployment bisa dijalankan manual.')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('scheme')
                            ->required()
                            ->default('ldap'),

                        TextInput::make('provision_mode')
                            ->required()
                            ->default('docker'),

                        TextInput::make('expose_mode')
                            ->required()
                            ->default('local'),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('container_name')
                            ->default('openldap-test'),

                        TextInput::make('docker_image')
                            ->required()
                            ->default('osixia/openldap:1.5.0'),
                    ]),
                ])
                ->columns(1),

            Section::make('Generated Artifacts')
                ->description('Akan otomatis di-refresh setelah create/update.')
                ->collapsed()
                ->schema([
                    Textarea::make('docker_command')
                        ->label('Docker Command')
                        ->rows(8)
                        ->columnSpanFull(),

                    Textarea::make('docker_compose_yaml')
                        ->label('Docker Compose YAML')
                        ->rows(12)
                        ->columnSpanFull(),

                    Textarea::make('kubernetes_manifest')
                        ->label('Kubernetes Manifest')
                        ->rows(18)
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('base_dn')->searchable()->limit(35),
                TextColumn::make('endpoint')
                    ->label('Endpoint')
                    ->state(fn (LdapServer $record): string => $record->endpoint())
                    ->copyable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_test_status')->label('Test')->badge(),
                IconColumn::make('is_registered_connection')->label('LDAP Conn')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->modalWidth('7xl')
                    ->mutateDataUsing(fn (array $data): array => static::mutateFormDataBeforeSave($data))
                    ->after(function (LdapServer $record): void {
                        app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($record);
                    }),

                ActionGroup::make([
                    Action::make('refreshArtifacts')
                        ->label('Refresh Artifacts')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (LdapServer $record): void {
                            app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($record);

                            Notification::make()
                                ->title('Artifacts refreshed')
                                ->success()
                                ->send();
                        }),

                    Action::make('testConnection')
                        ->label('Test LDAP Bind')
                        ->icon('heroicon-o-signal')
                        ->requiresConfirmation()
                        ->action(function (LdapServer $record): void {
                            $result = app(LdapServerProvisioningService::class)->testConnection($record);

                            $record->forceFill([
                                'last_tested_at' => now(),
                                'last_test_status' => $result['ok'] ? 'success' : 'failed',
                                'status' => $result['ok'] ? 'online' : 'error',
                                'last_error' => $result['ok'] ? null : $result['message'],
                            ])->save();

                            Notification::make()
                                ->title($result['ok'] ? 'LDAP bind success' : 'LDAP bind failed')
                                ->body($result['message'])
                                ->{$result['ok'] ? 'success' : 'danger'}()
                                ->send();
                        }),

                    Action::make('registerConnection')
                        ->label('Create / Update LDAP Connection')
                        ->icon('heroicon-o-link')
                        ->requiresConfirmation()
                        ->modalHeading('Create or update LDAP Connection?')
                        ->modalDescription('Data LDAP Server ini akan dibuat atau diperbarui ke menu LDAP Connections.')
                        ->action(function (LdapServer $record): void {
                            $result = app(LdapServerProvisioningService::class)->registerAsLdapConnection($record);

                            Notification::make()
                                ->title($result['ok'] ? 'LDAP Connection saved' : 'Save failed')
                                ->body($result['message'])
                                ->{$result['ok'] ? 'success' : 'danger'}()
                                ->send();
                        }),

                    DeleteAction::make(),
                ])->label('Actions'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        return app(LdapServerProvisioningService::class)->normalizePayload($data);
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        return app(LdapServerProvisioningService::class)->normalizePayload($data);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapServers::route('/'),
            'view' => Pages\ViewLdapServer::route('/{record}'),
        ];
    }
}
