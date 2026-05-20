<?php

namespace App\Filament\Resources\Operations;

use Filament\Forms\Components\Toggle;

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
                    ->columns(['default' => 1, 'md' => 2]),

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
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4]),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('preview_only')->boolean(),
                        IconEntry::make('safe_mode')->boolean(),
                        IconEntry::make('destructive')->boolean(),
                    ])
                    ->columns(['default' => 1, 'md' => 3]),

                Section::make('Logs')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID'),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('finished_at')->dateTime(),
                    ])
                    ->columns(['default' => 1, 'md' => 3]),
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
                    ->modalHeading('Create LDAP Transfer Preview')
                    ->modalWidth('5xl')
                    
                    ->modalSubmitActionLabel('Create Transfer')
                    ->modalWidth('5xl')
                    
                    ->createAnother(false)
                    ->label('New LDAP Transfer')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Create LDAP Transfer Preview')
                    ->modalWidth('5xl')
                    
                    ->modalSubmitActionLabel('Create Transfer')
                    ->modalWidth('5xl')
                    
                    ->createAnother(false)
                    ->mutateFormDataUsing(function (array $data): array {
                        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('universal_ldap_transfer_batches');

                        $defaults = [
                            'status' => 'draft',
                            'source_input_mode' => 'ldap_query',
                            'source_mode' => 'ldap_query',
                            'source_dn_list' => null,
                            'source_dn_file_path' => null,
                            'mode' => 'copy',
                            'transfer_mode' => 'copy',
                            'target_dn' => $data['target_parent_dn'] ?? null,
                            'target_dn_mode' => 'auto',
                            'legacy_target_base_dn' => null,
                            'preview_ldif' => null,
                            'preview_only' => true,
                            'safe_mode' => true,
                            'destructive' => false,
                            'total_entries' => 0,
                            'planned_entries' => 0,
                            'transferred_entries' => 0,
                            'failed_entries' => 0,
                        ];

                        foreach ($defaults as $key => $value) {
                            if (in_array($key, $columns, true) && ! array_key_exists($key, $data)) {
                                $data[$key] = $value;
                            }
                        }

                        if (in_array('target_dn', $columns, true) && blank($data['target_dn'] ?? null)) {
                            $data['target_dn'] = $data['target_parent_dn'] ?? null;
                        }

                        return $data;
                    }),
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
