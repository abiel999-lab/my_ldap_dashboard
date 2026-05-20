<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;
use App\Jobs\Operations\ExecuteLdapTransferJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Throwable;

class LdapTransferBatchResource extends Resource
{
    protected static ?string $model = LdapTransferBatch::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'LDAP Transfer Center';

    protected static ?string $modelLabel = 'LDAP Transfer';

    protected static ?string $pluralModelLabel = 'LDAP Transfer Center';

    protected static string|\UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('1. Transfer Identity')
                ->schema([
                    TextInput::make('name')
                        ->label('Transfer Name')
                        ->placeholder('Petra LDAP to Tiny LDAP - users transfer')
                        ->columnSpanFull(),

                    Select::make('source_ldap_connection_id')
                        ->label('Source LDAP')
                        ->options(fn (): array => static::connectionOptions())
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('target_ldap_connection_id')
                        ->label('Target LDAP')
                        ->options(fn (): array => static::connectionOptions())
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->columns(['default' => 1, 'lg' => 2]),

            Section::make('2. Source Selection')
                ->description('Pilih cara menentukan source entry. Bisa langsung berdasarkan DN list/CSV, atau filter dari OU/base DN.')
                ->schema([
                    Select::make('source_input_mode')
                        ->label('Source Input Mode')
                        ->options([
                            'dn_list' => 'DN List / Text / CSV Upload',
                            'filter' => 'Search by Base DN + LDAP Filter',
                        ])
                        ->default('dn_list')
                        ->live()
                        ->required(),

                    Textarea::make('source_dns_text')
                        ->label('Source DN List')
                        ->placeholder("uid=user001,ou=people,dc=example,dc=local\ncn=app-admin,ou=groups,dc=example,dc=local")
                        ->helperText('Satu DN per baris. Bisa paste dari CSV juga; kolom pertama akan dianggap DN.')
                        ->rows(8)
                        ->visible(fn ($get): bool => $get('source_input_mode') === 'dn_list')
                        ->columnSpanFull(),

                    FileUpload::make('source_file_path')
                        ->label('Upload Source DN File')
                        ->directory('ldap-transfer-sources')
                        ->acceptedFileTypes([
                            'text/plain',
                            'text/csv',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->helperText('Upload TXT/CSV. Kolom pertama dianggap Source DN.')
                        ->visible(fn ($get): bool => $get('source_input_mode') === 'dn_list')
                        ->columnSpanFull(),

                    TextInput::make('source_base_dn')
                        ->label('Source Base DN')
                        ->placeholder('ou=people,dc=example,dc=local')
                        ->visible(fn ($get): bool => $get('source_input_mode') === 'filter'),

                    TextInput::make('ldap_filter')
                        ->label('LDAP Filter')
                        ->default('(objectClass=*)')
                        ->placeholder('(objectClass=inetOrgPerson)')
                        ->visible(fn ($get): bool => $get('source_input_mode') === 'filter'),

                    Select::make('scope')
                        ->label('Scope')
                        ->options([
                            'base' => 'Base only',
                            'one' => 'One level',
                            'sub' => 'Subtree',
                        ])
                        ->default('base')
                        ->visible(fn ($get): bool => $get('source_input_mode') === 'filter'),
                ])
                ->columns(['default' => 1, 'lg' => 2]),

            Section::make('3. Target')
                ->description('Target DN tunggal. Untuk single source bisa full target DN. Untuk banyak source, target DN dipakai sebagai parent/container.')
                ->schema([
                    TextInput::make('target_dn')
                        ->label('Target DN')
                        ->placeholder('ou=transfer-target,dc=test,dc=local')
                        ->required()
                        ->columnSpanFull(),

                    Select::make('target_dn_mode')
                        ->label('Target DN Mode')
                        ->options([
                            'auto' => 'Auto',
                            'exact' => 'Exact DN, only for single source',
                            'parent' => 'Parent DN / container',
                        ])
                        ->default('auto')
                        ->helperText('Auto: single source + full DN = exact. Banyak source = parent/container.'),

                    TextInput::make('target_base_dn')
                        ->label('Legacy Target Base DN')
                        ->placeholder('Optional legacy fallback')
                        ->helperText('Opsional. Dipakai hanya jika Target DN kosong.')
                        ->disabled(),
                ])
                ->columns(['default' => 1, 'lg' => 2]),

            Section::make('4. Transfer Options')
                ->schema([
                    Select::make('mode')
                        ->label('Transfer Mode')
                        ->options([
                            'copy' => 'Copy only',
                            'move' => 'Move: copy then delete source',
                        ])
                        ->default('copy')
                        ->required(),

                    Select::make('collision_strategy')
                        ->label('If Target Exists')
                        ->options([
                            'skip' => 'Skip existing target',
                            'replace' => 'Replace existing target',
                            'fail' => 'Fail on existing target',
                        ])
                        ->default('skip')
                        ->required(),

                    Checkbox::make('include_operational_attributes')
                        ->label('Include operational attributes')
                        ->default(false)
                        ->helperText('Biasanya jangan aktif karena operational attributes sering read-only.'),

                    TagsInput::make('excluded_attributes')
                        ->label('Extra excluded attributes')
                        ->placeholder('userPassword')
                        ->helperText('Tambahan attribute yang tidak ikut ditransfer.'),
                ])
                ->columns(['default' => 1, 'lg' => 2]),

            Section::make('5. Preview LDIF')
                ->schema([
                    Textarea::make('preview_ldif')
                        ->label('Preview LDIF')
                        ->disabled()
                        ->rows(12)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->state(fn ($record): string => $record->name ?: 'Transfer #'.$record->id)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('source_input_mode')
                    ->label('Source Mode')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('mode')
                    ->label('Mode')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sourceConnection.name')
                    ->label('Source')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('targetConnection.name')
                    ->label('Target')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('source_base_dn')
                    ->label('Source Base')
                    ->limit(35)
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->limit(35)
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_entries')
                    ->label('Total')
                    ->sortable(),

                Tables\Columns\TextColumn::make('success_entries')
                    ->label('Success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('failed_entries')
                    ->label('Failed')
                    ->sortable(),

                Tables\Columns\TextColumn::make('skipped_entries')
                    ->label('Skipped')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y H:i:s')
                    ->sortable(),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record): string => static::getUrl('view', ['record' => $record])),

                ActionGroup::make([
                    Action::make('preview')
                        ->label('Preview Transfer')
                        ->icon('heroicon-o-eye')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(fn ($record) => static::queueTransfer($record, 'preview')),

                    Action::make('execute')
                        ->label('Execute Transfer')
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Execute LDAP Transfer?')
                        ->modalDescription('Operasi ini akan menyalin atau memindahkan LDAP entries sesuai konfigurasi batch.')
                        ->action(fn ($record) => static::queueTransfer($record, 'execute')),

                    Action::make('delete')
                        ->label('Delete Batch')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($record) => $record->delete()),
                ])
                    ->label('LDAP Operations')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->button()
                    ->color('primary'),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    public static function queueTransfer(LdapTransferBatch $record, string $operation): void
    {
        try {
            $executionId = static::createCommandExecution($record, $operation);

            $record->update([
                'status' => 'queued',
                'command_execution_id' => $executionId,
            ]);

            ExecuteLdapTransferJob::dispatch($record->id, $operation, $executionId);

            Notification::make()
                ->title('LDAP transfer queued')
                ->body(ucfirst($operation).' queued. Command Execution ID: '.$executionId)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('LDAP transfer queue failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function createCommandExecution(LdapTransferBatch $record, string $operation): int
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'command_type' => 'ldap_transfer_'.$operation.'_queued',
            'command' => 'queued job: ExecuteLdapTransferJob',
            'status' => 'running',
            'is_safe_mode' => true,
            'safe_mode' => true,
            'is_preview' => $operation === 'preview',
            'preview_mode' => $operation === 'preview',
            'destructive' => $operation !== 'preview',
            'module' => 'operations.ldap_transfer',
            'environment_context' => json_encode([
                'operation' => $operation,
                'batch_id' => $record->id,
                'source_input_mode' => $record->source_input_mode,
                'source_ldap_connection_id' => $record->source_ldap_connection_id,
                'target_ldap_connection_id' => $record->target_ldap_connection_id,
                'source_base_dn' => $record->source_base_dn,
                'target_dn' => $record->target_dn,
                'target_dn_mode' => $record->target_dn_mode,
                'ldap_filter' => $record->ldap_filter,
                'scope' => $record->scope,
                'mode' => $record->mode,
                'collision_strategy' => $record->collision_strategy,
                'queue' => 'ldap',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = DbSchema::getColumnListing('command_executions');

        $row = collect($row)
            ->filter(fn ($value, string $column): bool => in_array($column, $columns, true))
            ->toArray();

        return DB::table('command_executions')->insertGetId($row);
    }

    public static function connectionOptions(): array
    {
        return LdapConnection::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdapTransferBatches::route('/'),
            'view' => Pages\ViewLdapTransferBatch::route('/{record}'),
        ];
    }
}
