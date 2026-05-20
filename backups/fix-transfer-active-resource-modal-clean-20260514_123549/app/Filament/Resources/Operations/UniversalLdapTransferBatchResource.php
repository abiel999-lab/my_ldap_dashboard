<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\UniversalLdapTransferBatchResource\Pages;
use App\Jobs\Operations\ExecuteUniversalLdapTransferJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\UniversalLdapTransferBatch;
use App\Services\Operations\OperationJobFactory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnitEnum;

class UniversalLdapTransferBatchResource extends Resource
{
    protected static ?string $model = UniversalLdapTransferBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'LDAP Transfer Center';

    protected static ?string $modelLabel = 'LDAP Transfer';

    protected static ?string $pluralModelLabel = 'LDAP Transfer Center';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Source & Target')
                    ->schema([
                        TextInput::make('name')
                            ->label('Transfer Name')
                            ->required()
                            ->maxLength(255),

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

                        TextInput::make('source_base_dn')
                            ->label('Source Base DN')
                            ->required()
                            ->placeholder('dc=petra,dc=ac,dc=id'),

                        Textarea::make('target_parent_dn')
                            ->label('Target Parent DN')
                            ->required()
                            ->rows(2)
                            ->placeholder('ou=transfer-target,dc=test,dc=local')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Flexible Source Selection')
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

                        Select::make('search_scope')
                            ->label('Search Scope')
                            ->options([
                                'base' => 'Base only',
                                'one' => 'One level',
                                'sub' => 'Full subtree',
                            ])
                            ->default('sub')
                            ->required(),

                        TextInput::make('source_rdn_attribute')
                            ->label('Source RDN Attribute')
                            ->placeholder('ou / cn / uid'),

                        TextInput::make('source_rdn_value')
                            ->label('Source RDN Value')
                            ->placeholder('students / admin / abiel'),

                        Textarea::make('custom_source_dn')
                            ->label('Custom Source DN')
                            ->rows(2)
                            ->placeholder('ou=students,ou=people,dc=petra,dc=ac,dc=id')
                            ->columnSpanFull(),

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
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transfer Target')
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('name'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('sourceLdapConnection.name')->label('Source LDAP'),
                        TextEntry::make('targetLdapConnection.name')->label('Target LDAP'),
                        TextEntry::make('transfer_scope')->label('Scope'),
                        TextEntry::make('search_scope')->label('Search Scope'),
                        TextEntry::make('effective_source_dn')->label('Effective Source DN')->columnSpanFull(),
                        TextEntry::make('target_parent_dn')->label('Target Parent DN')->columnSpanFull(),
                        TextEntry::make('filter')->label('Filter'),
                        TextEntry::make('attributes')->label('Attributes'),
                    ])
                    ->columns(2),

                Section::make('Result')
                    ->schema([
                        TextEntry::make('total_entries')->label('Total'),
                        TextEntry::make('planned_entries')->label('Planned'),
                        TextEntry::make('transferred_entries')->label('Transferred'),
                        TextEntry::make('failed_entries')->label('Failed'),
                        TextEntry::make('output_path')->label('Output Path')->columnSpanFull(),
                        TextEntry::make('display_output_size')->label('Output Size'),
                        TextEntry::make('output_hash')->label('SHA256')->columnSpanFull(),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                        TextEntry::make('readOutputContent')
                            ->label('Transfer Plan Preview')
                            ->state(fn (UniversalLdapTransferBatch $record): string => $record->readOutputContent())
                            ->columnSpanFull(),
                    ])
                    ->columns(4),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('preview_only')->boolean(),
                        IconEntry::make('safe_mode')->boolean(),
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
                TextColumn::make('name')->searchable()->weight('semibold')->limit(40),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('sourceLdapConnection.name')->label('Source')->badge()->limit(20),
                TextColumn::make('targetLdapConnection.name')->label('Target')->badge()->limit(20),
                TextColumn::make('effective_source_dn')->label('Source DN')->limit(45),
                TextColumn::make('target_parent_dn')->label('Target Parent DN')->limit(45),
                TextColumn::make('total_entries')->label('Total')->sortable(),
                TextColumn::make('planned_entries')->label('Planned')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New LDAP Transfer')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Create LDAP Transfer Preview')
                    ->modalSubmitActionLabel('Create Transfer')
                    ->modalWidth('7xl')
                    ->createAnother(false),
            ])
            ->bulkActions([
                BulkAction::make('safeBulkDelete')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $protected = ['queued', 'running', 'processing'];
                        $deleted = 0;
                        $blocked = 0;

                        foreach ($records as $record) {
                            if (in_array((string) $record->status, $protected, true)) {
                                $blocked++;
                                continue;
                            }

                            if (filled($record->output_path) && Storage::disk('local')->exists((string) $record->output_path)) {
                                Storage::disk('local')->delete((string) $record->output_path);
                            }

                            $record->delete();
                            $deleted++;
                        }

                        if ($deleted > 0) {
                            Notification::make()
                                ->title('LDAP transfer records deleted')
                                ->body($deleted.' records deleted successfully.')
                                ->success()
                                ->send();
                        }

                        if ($blocked > 0) {
                            Notification::make()
                                ->title('Some records were protected')
                                ->body($blocked.' queued/running records were not deleted.')
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('queuePreview')
                    ->label('Queue Preview')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (UniversalLdapTransferBatch $record): bool => in_array($record->status, ['draft', 'failed', 'success', 'partial_success'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Queue LDAP transfer preview?')
                    ->modalDescription('This only generates a transfer plan LDIF. It will not write to the target LDAP.')
                    ->action(function (UniversalLdapTransferBatch $record): void {
                        if (blank($record->effective_source_dn)) {
                            Notification::make()
                                ->title('Effective Source DN is empty')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (blank($record->target_parent_dn)) {
                            Notification::make()
                                ->title('Target Parent DN is empty')
                                ->danger()
                                ->send();

                            return;
                        }

                        $operationJob = app(OperationJobFactory::class)->createQueued([
                            'operation_type' => 'universal_ldap_transfer',
                            'operation_action' => 'generate_transfer_preview',
                            'module' => 'operations.transfer',
                            'title' => 'LDAP Transfer Preview - '.$record->name,
                            'queue_name' => 'operations',
                            'source' => 'filament',
                            'target_type' => UniversalLdapTransferBatch::class,
                            'target_key' => (string) $record->id,
                            'target_dn' => $record->effective_source_dn,
                            'ldap_connection_id' => $record->source_ldap_connection_id,
                            'created_by' => Auth::id(),
                            'total_items' => 1,
                            'pending_items' => 1,
                            'payload' => [
                                'transfer_batch_id' => $record->id,
                                'source_ldap_connection_id' => $record->source_ldap_connection_id,
                                'target_ldap_connection_id' => $record->target_ldap_connection_id,
                                'effective_source_dn' => $record->effective_source_dn,
                                'target_parent_dn' => $record->target_parent_dn,
                                'filter' => $record->filter,
                                'search_scope' => $record->search_scope,
                                'preview_only' => true,
                            ],
                            'metadata' => [
                                'transfer_batch_id' => $record->id,
                                'source_ldap_connection_id' => $record->source_ldap_connection_id,
                                'target_ldap_connection_id' => $record->target_ldap_connection_id,
                                'effective_source_dn' => $record->effective_source_dn,
                                'target_parent_dn' => $record->target_parent_dn,
                                'preview_only' => true,
                                'safe_mode' => true,
                                'destructive' => false,
                            ],
                        ]);

                        $record->forceFill([
                            'status' => 'queued',
                            'operation_job_id' => $operationJob->id,
                            'message' => 'LDAP transfer preview queued.',
                            'started_at' => null,
                            'finished_at' => null,
                        ])->save();

                        ExecuteUniversalLdapTransferJob::dispatch($operationJob->id, $record->id);

                        Notification::make()
                            ->title('LDAP transfer preview queued successfully')
                            ->body('Operation Job ID: '.$operationJob->id)
                            ->success()
                            ->send();
                    }),

                Action::make('downloadPlan')
                    ->label('Download Plan')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (UniversalLdapTransferBatch $record): bool => $record->hasOutputFile())
                    ->url(fn (UniversalLdapTransferBatch $record): string => route('filament.admin.resources.operations.universal-ldap-transfer-batches.view', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUniversalLdapTransferBatches::route('/'),
            'view' => Pages\ViewUniversalLdapTransferBatch::route('/{record}'),
        ];
    }
}
