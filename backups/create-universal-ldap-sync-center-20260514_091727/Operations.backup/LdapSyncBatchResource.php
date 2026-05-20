<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdapSyncBatchResource\Pages;
use App\Jobs\Operations\ExecuteUniversalLdapSyncJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapSyncBatch;
use App\Models\Operations\OperationJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class LdapSyncBatchResource extends Resource
{
    protected static ?string $model = LdapSyncBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'LDAP Sync Center';

    protected static ?string $modelLabel = 'LDAP Sync';

    protected static ?string $pluralModelLabel = 'LDAP Sync Center';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Source')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => LdapConnection::query()
                                ->active()
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (LdapConnection $connection): array => [
                                    $connection->id => $connection->name.' | '.$connection->host.':'.$connection->port,
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->default(fn (): ?string => LdapConnection::query()->active()->where('is_default', true)->value('base_dn')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Flexible Sync Target')
                    ->schema([
                        Select::make('sync_scope')
                            ->label('Sync What?')
                            ->options([
                                'full' => 'Full Base DN',
                                'ou' => 'Specific OU',
                                'cn' => 'Specific CN',
                                'uid' => 'Specific UID',
                                'custom_dn' => 'Custom DN',
                            ])
                            ->default('full')
                            ->required(),

                        Select::make('search_scope')
                            ->label('Search Scope')
                            ->options([
                                'base' => 'Base only',
                                'one' => 'One level',
                                'sub' => 'Full subtree',
                            ])
                            ->default('sub')
                            ->required(),

                        TextInput::make('target_rdn_attribute')
                            ->label('RDN Attribute')
                            ->placeholder('ou / cn / uid'),

                        TextInput::make('target_rdn_value')
                            ->label('RDN Value')
                            ->placeholder('students / admin / abiel'),

                        Textarea::make('custom_target_dn')
                            ->label('Custom Target DN')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->default('(objectClass=*)'),

                        TextInput::make('attributes')
                            ->label('Attributes')
                            ->placeholder('* or * + or dn cn uid mail objectClass'),

                        TextInput::make('size_limit')
                            ->label('Size Limit')
                            ->numeric()
                            ->default(5000)
                            ->required(),

                        TextInput::make('page_size')
                            ->label('Page Size')
                            ->numeric()
                            ->default(1000)
                            ->required(),

                        Select::make('status')
                            ->options(['draft' => 'Draft'])
                            ->default('draft')
                            ->hidden(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync Target')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('sync_scope')->label('Sync Scope'),
                        TextEntry::make('search_scope')->label('Search Scope'),
                        TextEntry::make('base_dn')->label('Base DN')->columnSpanFull(),
                        TextEntry::make('display_target')->label('Effective Sync DN')->columnSpanFull(),
                        TextEntry::make('filter')->label('Filter'),
                        TextEntry::make('attributes')->label('Attributes')->placeholder('*'),
                        TextEntry::make('size_limit')->label('Size Limit'),
                        TextEntry::make('page_size')->label('Page Size'),
                    ])
                    ->columns(2),

                Section::make('Result')
                    ->schema([
                        TextEntry::make('total_entries')->label('Total'),
                        TextEntry::make('created_entries')->label('Created'),
                        TextEntry::make('updated_entries')->label('Updated'),
                        TextEntry::make('failed_entries')->label('Failed'),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                    ])
                    ->columns(4),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->boolean(),
                        IconEntry::make('preview_mode')->boolean(),
                        IconEntry::make('destructive')->boolean(),
                    ])
                    ->columns(3),

                Section::make('Logs')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID'),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('finished_at')->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->weight('semibold')->limit(35),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('ldapConnection.name')->label('LDAP')->limit(20),
                TextColumn::make('sync_scope')->label('Scope')->badge(),
                TextColumn::make('display_target')->label('Target DN')->limit(50),
                TextColumn::make('filter')->limit(30),
                TextColumn::make('total_entries')->label('Total')->sortable(),
                TextColumn::make('created_entries')->label('Created')->sortable(),
                TextColumn::make('updated_entries')->label('Updated')->sortable(),
                TextColumn::make('failed_entries')->label('Failed')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New LDAP Sync')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Create LDAP Sync')
                    ->modalSubmitActionLabel('Create Sync')
                    ->modalWidth('7xl')
                    ->createAnother(false),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('queueSync')
                    ->label('Queue Sync')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (LdapSyncBatch $record): bool => in_array($record->status, ['draft', 'failed'], true))
                    ->requiresConfirmation()
                    ->action(function (LdapSyncBatch $record): void {
                        if (blank($record->effective_base_dn)) {
                            Notification::make()
                                ->title('Effective Sync DN is empty')
                                ->danger()
                                ->send();

                            return;
                        }

                        $operationJob = OperationJob::query()->create([
                            'operation_type' => 'universal_ldap_sync',
                            'type' => 'universal_ldap_sync',
                            'name' => 'LDAP Sync - '.$record->name,
                            'module' => 'operations.sync',
                            'operation_action' => 'execute_universal_ldap_sync',
                            'action' => 'execute_universal_ldap_sync',
                            'status' => 'queued',
                            'source' => 'filament',
                            'target_type' => LdapSyncBatch::class,
                            'target_key' => (string) $record->id,
                            'target_dn' => $record->effective_base_dn,
                            'ldap_connection_id' => $record->ldap_connection_id,
                            'created_by' => Auth::id(),
                            'metadata' => [
                                'ldap_sync_batch_id' => $record->id,
                                'effective_base_dn' => $record->effective_base_dn,
                                'filter' => $record->filter,
                                'search_scope' => $record->search_scope,
                            ],
                        ]);

                        $record->forceFill([
                            'status' => 'queued',
                            'operation_job_id' => $operationJob->id,
                            'message' => 'LDAP sync queued.',
                        ])->save();

                        ExecuteUniversalLdapSyncJob::dispatch($operationJob->id, $record->id);

                        Notification::make()
                            ->title('LDAP sync queued')
                            ->body('Operation Job #'.$operationJob->id.' created.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapSyncBatches::route('/'),
            'view' => Pages\ViewLdapSyncBatch::route('/{record}'),
        ];
    }
}
