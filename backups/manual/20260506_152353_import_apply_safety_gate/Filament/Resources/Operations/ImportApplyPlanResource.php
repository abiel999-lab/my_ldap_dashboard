<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;
use UnitEnum;

class ImportApplyPlanResource extends Resource
{
    protected static ?string $model = ImportApplyPlan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Import Apply Plans';

    protected static ?string $modelLabel = 'Import Apply Plan';

    protected static ?string $pluralModelLabel = 'Import Apply Plans';

    protected static ?int $navigationSort = 25;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('plan_type')->label('Type')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'draft' => 'gray',
                                'queued' => 'warning',
                                'running' => 'info',
                                'success' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('message')->label('Message')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Summary')
                    ->schema([
                        TextEntry::make('total_rows')->label('Total Rows'),
                        TextEntry::make('planned_create_rows')->label('Planned Create'),
                        TextEntry::make('planned_update_rows')->label('Planned Update'),
                        TextEntry::make('skipped_rows')->label('Skipped'),
                        TextEntry::make('failed_rows')->label('Failed'),
                    ])
                    ->columns(5),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('dry_run')->label('Dry Run')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                    ])
                    ->columns(3),

                Section::make('Output')
                    ->schema([
                        TextEntry::make('output_path')->label('Output Path')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_output_size')->label('Output Size'),
                        TextEntry::make('output_hash')->label('SHA256')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('ldif_preview')
                            ->label('LDIF Apply Plan Preview')
                            ->state(fn (ImportApplyPlan $record): string => $record->readOutputContent(60000))
                            ->placeholder('No output file.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Links / Timeline')
                    ->schema([
                        TextEntry::make('import_batch_id')->label('Import Batch ID'),
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'import_batch_id',
                    'name',
                    'status',
                    'plan_type',
                    'total_rows',
                    'planned_create_rows',
                    'skipped_rows',
                    'failed_rows',
                    'output_path',
                    'output_size_bytes',
                    'operation_job_id',
                    'created_at',
                ])
                ->latest('id'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('semibold')
                    ->limit(45)
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'queued' => 'warning',
                        'running' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('planned_create_rows')->label('Create')->sortable(),
                TextColumn::make('skipped_rows')->label('Skip')->sortable(),
                TextColumn::make('failed_rows')->label('Fail')->sortable(),
                TextColumn::make('display_output_size')->label('Size'),
                TextColumn::make('operation_job_id')->label('Job')->placeholder('N/A'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportApplyPlans::route('/'),
            'view' => Pages\ViewImportApplyPlan::route('/{record}'),
        ];
    }
}
