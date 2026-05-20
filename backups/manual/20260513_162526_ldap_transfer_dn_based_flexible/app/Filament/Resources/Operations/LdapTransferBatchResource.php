<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;
use App\Jobs\Operations\ExecuteLdapTransferJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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

    protected static string|\UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Transfer Name')
                ->placeholder('Transfer people from LDAP A to LDAP B'),

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

            TextInput::make('source_base_dn')
                ->label('Source Base DN')
                ->placeholder('ou=people,dc=petra,dc=ac,dc=id')
                ->required(),

            TextInput::make('target_base_dn')
                ->label('Target Base DN')
                ->placeholder('ou=people,dc=target,dc=local')
                ->required(),

            TextInput::make('ldap_filter')
                ->label('LDAP Filter')
                ->default('(objectClass=*)')
                ->required(),

            Select::make('scope')
                ->label('Scope')
                ->options([
                    'base' => 'Base only',
                    'one' => 'One level',
                    'sub' => 'Subtree',
                ])
                ->default('sub')
                ->required(),

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
                ->helperText('Biasanya jangan aktif, karena operational attributes sering read-only.'),

            TagsInput::make('excluded_attributes')
                ->label('Extra excluded attributes')
                ->placeholder('userPassword')
                ->helperText('Tambahan attribute yang tidak ikut ditransfer.'),

            Textarea::make('preview_ldif')
                ->label('Preview LDIF')
                ->disabled()
                ->rows(10)
                ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('target_base_dn')
                    ->label('Target Base')
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
            ])            ->actions([
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
                'source_ldap_connection_id' => $record->source_ldap_connection_id,
                'target_ldap_connection_id' => $record->target_ldap_connection_id,
                'source_base_dn' => $record->source_base_dn,
                'target_base_dn' => $record->target_base_dn,
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
            'create' => Pages\CreateLdapTransferBatch::route('/create'),
            'view' => Pages\ViewLdapTransferBatch::route('/{record}'),
            'edit' => Pages\EditLdapTransferBatch::route('/{record}/edit'),
        ];
    }
}
