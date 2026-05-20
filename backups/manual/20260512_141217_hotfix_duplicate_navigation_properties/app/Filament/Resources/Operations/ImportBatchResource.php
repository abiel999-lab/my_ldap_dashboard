<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\ImportBatchResource\Pages\CreateImportBatch;
use App\Filament\Resources\Operations\ImportBatchResource\Pages\EditImportBatch;
use App\Filament\Resources\Operations\ImportBatchResource\Pages\ListImportBatches;
use App\Filament\Resources\Operations\ImportBatchResource\Pages\ViewImportBatch;
use App\Support\Ui\StatusLabel;

use App\Filament\Resources\Operations\ImportBatchResource\Pages;
use App\Filament\Resources\Operations\ImportBatchResource\RelationManagers\RowsRelationManager;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\ImportBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\ImportPreviewDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
use UnitEnum;

class ImportBatchResource extends Resource
{
    protected static ?string $navigationGroup = '2. Operations';
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Imports CRUD Operations';

    protected static ?string $modelLabel = 'Import Batch';

    protected static ?string $pluralModelLabel = 'Imports CRUD Operations';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import File')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('import_type')
                            ->label('Import Type')
                            ->options([
                                'csv' => 'CSV',
                                'json' => 'JSON',
                                'ldif' => 'LDIF',
                            ])
                            ->default('csv')
                            
                            
                            ->required(),

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
                            ->helperText('Leave empty for All LDAP / Preview Only. Choose one LDAP connection before real apply.'),

                        FileUpload::make('file_path')
                            ->maxSize(5120)
                            ->label('Upload CSV / LDIF / JSON File')
                            ->disk('local')
                            ->directory('imports/uploads')
                            ->preserveFilenames()
                            ->required()
                            ->helperText('Upload .csv, .ldif, or .json. Format is validated by file extension during save, not by browser MIME type. No LDAP data changes during preview.'),

                        TextInput::make('original_filename')
                            ->label('Original Filename')
                            ->maxLength(255)
                            ->helperText('Optional. The uploaded storage path is recorded automatically.'),

                        TextInput::make('base_dn')
                            ->label('Target Base DN')
                            ->maxLength(1000)
                            ->default(fn (): ?string => LdapConnection::query()->where('is_default', true)->value('base_dn')),

                        TextInput::make('identifier_attribute')
                            ->label('Identifier Attribute')
                            ->default('uid')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('dn_template')
                            ->label('DN Template')
                            ->placeholder('uid={uid},ou=people,dc=petra,dc=ac,dc=id')
                            ->maxLength(1000)
                            ->helperText('Future stage. Current preview uses identifier_attribute + base_dn if DN is missing.'),
                    ])
                    ->columns(2),

                Section::make('Safety')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Draft',
                                'preview_queued' => 'Preview Queued',
                                'previewing' => 'Previewing',
                                'previewed' => 'Previewed',
                                'previewed_with_errors' => 'Previewed With Errors',
                                'failed' => 'Failed',
                                'ready_to_apply' => 'Ready To Apply',
                                'applied' => 'Applied',
                            ])
                            ->default('draft')
                            ->required(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import Batch')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('import_type')->label('Type')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'draft' => 'gray',
                                'preview_queued' => 'warning',
                                'previewing' => 'info',
                                'previewed' => 'success',
                                'previewed_with_errors' => 'warning',
                                'failed' => 'danger',
                                'ready_to_apply' => 'success',
                                'applied' => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('base_dn')->label('Base DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('identifier_attribute')->label('Identifier Attribute'),
                        TextEntry::make('file_path')->label('File Path')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_file_size')->label('File Size'),
                        TextEntry::make('file_hash')->label('SHA256')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('message')->label('Message')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Preview Summary')
                    ->schema([
                        TextEntry::make('total_rows')->label('Total Rows'),
                        TextEntry::make('valid_rows')->label('Valid Rows'),
                        TextEntry::make('invalid_rows')->label('Invalid Rows'),
                        TextEntry::make('duplicate_rows')->label('Duplicate Rows'),
                        TextEntry::make('conflict_rows')->label('Conflict Rows'),
                        TextEntry::make('will_create_rows')->label('Will Create'),
                        TextEntry::make('will_update_rows')->label('Will Update'),
                        TextEntry::make('will_skip_rows')->label('Will Skip'),
                        TextEntry::make('will_fail_rows')->label('Will Fail'),
                    ])
                    ->columns(3),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('preview_only')->label('Preview Only')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                    ])
                    ->columns(3),

                Section::make('Links / Timeline')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('preview_started_at')->label('Preview Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('preview_finished_at')->label('Preview Finished At')->dateTime()->placeholder('N/A'),
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
                    'import_type',
                    'status',
                    'file_path',
                    'base_dn',
                    'total_rows',
                    'valid_rows',
                    'invalid_rows',
                    'duplicate_rows',
                    'will_create_rows',
                    'will_fail_rows',
                    'operation_job_id',
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

                TextColumn::make('import_type')
                    ->label('Type')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => StatusLabel::importBatch($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'preview_queued' => 'warning',
                        'previewing' => 'info',
                        'previewed' => 'success',
                        'previewed_with_errors' => 'warning',
                        'failed' => 'danger',
                        'ready_to_apply' => 'success',
                        'applied' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('total_rows')->label('Rows')->sortable(),
                TextColumn::make('valid_rows')->label('Valid')->sortable(),
                TextColumn::make('invalid_rows')->label('Invalid')->sortable(),
                TextColumn::make('duplicate_rows')->label('Dup')->sortable(),
                TextColumn::make('will_create_rows')->label('Create')->sortable(),
                TextColumn::make('will_fail_rows')->label('Fail')->sortable(),
                TextColumn::make('operation_job_id')->label('Job')->placeholder('N/A'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('import_type')
                    ->label('Type')
                    ->options([
                        'csv' => 'CSV',
                        'json' => 'JSON',
                        'ldif' => 'LDIF',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'preview_queued' => 'Preview Queued',
                        'previewing' => 'Previewing',
                        'previewed' => 'Previewed',
                        'previewed_with_errors' => 'Previewed With Errors',
                        'failed' => 'Failed',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ImportBatch $record): bool => in_array($record->status, ['draft', 'failed', 'previewed_with_errors'], true)),

                Action::make('queuePreview')
                    ->label('Queue Preview')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->visible(fn (ImportBatch $record): bool => in_array($record->status, ['draft', 'failed', 'previewed_with_errors'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Queue import preview?')
                    ->modalDescription('This parses and validates the uploaded file in the import queue. No LDAP data will be changed.')
                    ->action(function (ImportBatch $record): void {
                        $result = app(ImportPreviewDispatcher::class)->queuePreview($record);

                        if (! $result['ok']) {
                            Notification::make()
                                ->title('Failed to queue import preview')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        $operationJob = $result['operation_job'];

                        Notification::make()
                            ->title('Import preview queued')
                            ->body('Operation Job #'.$operationJob->id.' was created.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'create' => CreateImportBatch::route('/create'),
            'view' => ViewImportBatch::route('/{record}'),
            'edit' => EditImportBatch::route('/{record}/edit'),
        ];
    }
}
