<?php

namespace App\Filament\Resources\Operations;

use App\Support\Ui\StatusLabel;

use App\Filament\Resources\Operations\ImportApplyPlanResource\Pages;
use App\Models\Operations\ImportApplyPlan;
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
                            ->formatStateUsing(fn (?string $state): string => StatusLabel::importApplyPlan($state))
                            ->color(fn (?string $state): string => StatusLabel::importApplyPlanColor($state)),
                        TextEntry::make('approval_status')
                            ->label('Approval')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => StatusLabel::approval($state))
                            ->color(fn (?string $state): string => StatusLabel::approvalColor($state)),
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
                        IconEntry::make('dry_run')->label('Dry')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                        TextEntry::make('apply_blocked_reason')->label('Apply Blocked Reason')->placeholder('No blocking reason.')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Approval')
                    ->schema([
                        TextEntry::make('approval_note')->label('Approval Note')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('approved_by')->label('Approved By User ID')->placeholder('N/A'),
                        TextEntry::make('approved_at')->label('Approved At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('dry_run_verified_at')->label('Dry Run Verified At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('dry_run_verified_by')->label('Dry Run Verified By User ID')->placeholder('N/A'),
                        TextEntry::make('dry_run_command_execution_id')->label('Dry Run Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('dry_run_output_summary')->label('Dry Run Output Summary')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('dry_run_error_message')->label('Dry Run Error Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('real_apply_started_at')->label('Real Apply Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('real_apply_finished_at')->label('Real Apply Finished At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('real_apply_by')->label('Real Apply By User ID')->placeholder('N/A'),
                        TextEntry::make('real_apply_command_execution_id')->label('Real Apply Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('real_apply_output_summary')->label('Real Apply Output Summary')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('real_apply_error_message')->label('Real Apply Error Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('post_apply_verified_at')->label('Post Apply Verified At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('post_apply_verified_by')->label('Post Apply Verified By User ID')->placeholder('N/A'),
                        TextEntry::make('post_apply_command_execution_id')->label('Post Apply Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('post_apply_verified_count')->label('Post Apply Verified Count'),
                        TextEntry::make('post_apply_missing_count')->label('Post Apply Missing Count'),
                        TextEntry::make('post_apply_output_summary')->label('Post Apply Output Summary')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('post_apply_error_message')->label('Post Apply Error Message')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('rejected_by')->label('Rejected By User ID')->placeholder('N/A'),
                        TextEntry::make('rejected_at')->label('Rejected At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

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

                Section::make('Recovery')
                    ->schema([
                        TextEntry::make('recovery_summary')
                            ->label('Recovery Summary')
                            ->state(fn (ImportApplyPlan $record): string => $record->recoverySummary())
                            ->columnSpanFull(),

                        TextEntry::make('replacement_of_plan_id')
                            ->label('Replacement Of Plan ID')
                            ->placeholder('N/A'),

                        TextEntry::make('replaced_by_plan_id')
                            ->label('Replaced By Plan ID')
                            ->placeholder('N/A'),

                        TextEntry::make('archived_at')
                            ->label('Archived At')
                            ->dateTime()
                            ->placeholder('N/A'),

                        TextEntry::make('archive_reason')
                            ->label('Archive Reason')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Evidence Links')
                    ->schema([
                        TextEntry::make('evidence_summary')
                            ->label('Evidence Summary')
                            ->state(fn (ImportApplyPlan $record): string => $record->evidenceSummary())
                            ->columnSpanFull(),

                        TextEntry::make('import_batch_link')
                            ->label('Import Batch')
                            ->state(fn (ImportApplyPlan $record): string => 'Open Import Batch #'.$record->import_batch_id)
                            ->url(fn (ImportApplyPlan $record): string => $record->importBatchUrl())
                            ->openUrlInNewTab(),

                        TextEntry::make('operation_job_link')
                            ->label('Operation Job')
                            ->state(fn (ImportApplyPlan $record): string => $record->operation_job_id ? 'Open Operation Job #'.$record->operation_job_id : 'N/A')
                            ->url(fn (ImportApplyPlan $record): ?string => $record->operationJobUrl())
                            ->openUrlInNewTab(),

                        TextEntry::make('dry_run_command_link')
                            ->label('Dry Run Command')
                            ->state(fn (ImportApplyPlan $record): string => $record->dry_run_command_execution_id ? 'Open Command #'.$record->dry_run_command_execution_id : 'N/A')
                            ->url(fn (ImportApplyPlan $record): ?string => $record->dryRunCommandExecutionUrl())
                            ->openUrlInNewTab(),

                        TextEntry::make('real_apply_command_link')
                            ->label('Real Apply Command')
                            ->state(fn (ImportApplyPlan $record): string => $record->real_apply_command_execution_id ? 'Open Command #'.$record->real_apply_command_execution_id : 'N/A')
                            ->url(fn (ImportApplyPlan $record): ?string => $record->realApplyCommandExecutionUrl())
                            ->openUrlInNewTab(),

                        TextEntry::make('post_apply_command_link')
                            ->label('Post Apply Command')
                            ->state(fn (ImportApplyPlan $record): string => $record->post_apply_command_execution_id ? 'Open Command #'.$record->post_apply_command_execution_id : 'N/A')
                            ->url(fn (ImportApplyPlan $record): ?string => $record->postApplyCommandExecutionUrl())
                            ->openUrlInNewTab(),

                        TextEntry::make('audit_logs_link')
                            ->label('Related Audit Logs')
                            ->state('Open Audit Logs')
                            ->url(fn (ImportApplyPlan $record): string => $record->relatedAuditLogsUrl())
                            ->openUrlInNewTab(),
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
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'import_batch_id',
                    'name',
                    'status',
                    'approval_status',
                    'plan_type',
                    'total_rows',
                    'planned_create_rows',
                    'skipped_rows',
                    'failed_rows',
                    'output_path',
                    'output_size_bytes',
                    'operation_job_id',
                    'replacement_of_plan_id',
                    'replaced_by_plan_id',
                    'archived_at',
                    'post_apply_verified_count',
                    'post_apply_missing_count',
                    'created_at',
                ])
                )
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
                    ->formatStateUsing(fn (?string $state): string => StatusLabel::importApplyPlan($state))
                    ->color(fn (?string $state): string => StatusLabel::importApplyPlanColor($state))
                    ->sortable(),

                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => StatusLabel::approval($state))
                    ->color(fn (?string $state): string => StatusLabel::approvalColor($state))
                    ->sortable(),

                TextColumn::make('planned_create_rows')->label('Create')->sortable(),
                TextColumn::make('skipped_rows')->label('Skip')->sortable(),
                TextColumn::make('failed_rows')->label('Fail')->sortable(),
                TextColumn::make('display_output_size')->label('Size'),
                TextColumn::make('replacement_of_plan_id')->label('Replacement Of')->placeholder('N/A'),
                TextColumn::make('replaced_by_plan_id')->label('Replaced By')->placeholder('N/A'),
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
