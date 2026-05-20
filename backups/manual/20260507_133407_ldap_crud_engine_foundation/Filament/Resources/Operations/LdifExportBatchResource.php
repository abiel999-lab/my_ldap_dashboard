<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\LdifExportBatchResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdifExportBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\LdifExportDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;
use UnitEnum;

class LdifExportBatchResource extends Resource
{
    protected static ?string $model = LdifExportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'LDIF Exports';

    protected static ?string $modelLabel = 'LDIF Export';

    protected static ?string $pluralModelLabel = 'LDIF Exports';

    protected static ?int $navigationSort = 26;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Export Target')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->options(fn (): array => LdapConnection::query()
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('If empty, default LDAP connection is used.'),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->maxLength(1000)
                            ->default(fn (): ?string => LdapConnection::query()->where('is_default', true)->value('base_dn')),

                        TextInput::make('filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->default('(objectClass=*)')
                            ->maxLength(500),

                        TextInput::make('attributes')
                            ->label('Attributes')
                            ->placeholder('dn cn mail objectClass or leave empty for *')
                            ->maxLength(1000)
                            ->helperText('Separate attributes with space or comma. Leave empty to export all normal attributes.'),

                        TextInput::make('size_limit')
                            ->label('Size Limit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->default(500)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Safety')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Draft',
                                'queued' => 'Queued',
                                'running' => 'Running',
                                'success' => 'Success',
                                'failed' => 'Failed',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('draft')
                            ->required(),

                        Textarea::make('message')
                            ->label('Message')
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Export')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'draft' => 'gray',
                                'queued' => 'warning',
                                'running' => 'info',
                                'success' => 'success',
                                'failed' => 'danger',
                                'cancelled' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('base_dn')->label('Base DN')->columnSpanFull(),
                        TextEntry::make('filter')->label('Filter'),
                        TextEntry::make('attributes')->label('Attributes')->placeholder('*'),
                        TextEntry::make('size_limit')->label('Size Limit'),
                    ])
                    ->columns(2),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('preview_mode')->label('Preview Mode')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                    ])
                    ->columns(3),

                Section::make('Output')
                    ->schema([
                        TextEntry::make('output_path')->label('Output Path')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_output_size')->label('Output Size'),
                        TextEntry::make('output_hash')->label('SHA256')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('message')->label('Message')->placeholder('N/A')->columnSpanFull(),

                        TextEntry::make('ldif_content_preview')
                            ->label('LDIF Content Preview')
                            ->state(fn (LdifExportBatch $record): string => $record->readOutputContent(60000))
                            ->placeholder('No LDIF content available.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Links')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('command_execution_id')->label('Command Execution ID')->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'name',
                    'status',
                    'ldap_connection_id',
                    'base_dn',
                    'filter',
                    'size_limit',
                    'output_path',
                    'output_size_bytes',
                    'operation_job_id',
                    'command_execution_id',
                    'created_at',
                    'updated_at',
                ])
                )
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'queued' => 'warning',
                        'running' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('base_dn')
                    ->label('Base DN')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('filter')
                    ->label('Filter')
                    ->limit(28),

                TextColumn::make('size_limit')
                    ->label('Limit')
                    ->sortable(),

                TextColumn::make('display_output_size')
                    ->label('Size'),

                TextColumn::make('operation_job_id')
                    ->label('Job')
                    ->placeholder('N/A'),

                TextColumn::make('command_execution_id')
                    ->label('Cmd')
                    ->placeholder('N/A'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadLdif')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->visible(fn (LdifExportBatch $record): bool => $record->hasOutputFile())
                    ->action(function (LdifExportBatch $record) {
                        app(AuditLogger::class)->log([
                            'module' => 'operations.export',
                            'action' => 'download_ldif_export',
                            'status' => 'success',
                            'target_type' => LdifExportBatch::class,
                            'target_key' => (string) $record->id,
                            'target_dn' => $record->base_dn,
                            'ldap_connection_id' => $record->ldap_connection_id,
                            'operation_job_id' => $record->operation_job_id,
                            'request_payload' => [
                                'output_path' => $record->output_path,
                                'output_size_bytes' => $record->output_size_bytes,
                                'output_hash' => $record->output_hash,
                            ],
                        ]);

                        return Response::download(
                            $record->outputAbsolutePath(),
                            $record->outputFilename(),
                            [
                                'Content-Type' => 'text/plain',
                            ],
                        );
                    }),

                EditAction::make()
                    ->visible(fn (LdifExportBatch $record): bool => in_array($record->status, ['draft', 'failed'], true)),

                Action::make('queueExport')
                    ->label('Queue Export')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (LdifExportBatch $record): bool => in_array($record->status, ['draft', 'failed'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Queue LDIF export?')
                    ->modalDescription('This creates an Operation Job and runs read-only ldapsearch export in the export queue.')
                    ->action(function (LdifExportBatch $record): void {
                        $result = app(LdifExportDispatcher::class)->queueExport($record);

                        if (! $result['ok']) {
                            Notification::make()
                                ->title('Failed to queue LDIF export')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        $operationJob = $result['operation_job'];

                        Notification::make()
                            ->title('LDIF export queued')
                            ->body('Operation Job #'.$operationJob->id.' was created.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLdifExportBatches::route('/'),
            'create' => Pages\CreateLdifExportBatch::route('/create'),
            'view' => Pages\ViewLdifExportBatch::route('/{record}'),
            'edit' => Pages\EditLdifExportBatch::route('/{record}/edit'),
        ];
    }
}
