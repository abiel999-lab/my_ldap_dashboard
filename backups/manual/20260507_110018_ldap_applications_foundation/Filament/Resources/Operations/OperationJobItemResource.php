<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\OperationJobItemResource\Pages;
use App\Models\Operations\OperationJobItem;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use UnitEnum;

class OperationJobItemResource extends Resource
{
    protected static ?string $model = OperationJobItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Operation Job Items';

    protected static ?string $modelLabel = 'Operation Job Item';

    protected static ?string $pluralModelLabel = 'Operation Job Items';

    protected static ?int $navigationSort = 11;

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
                Section::make('Item')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('target_type')->label('Target Type')->placeholder('N/A'),
                        TextEntry::make('display_target')->label('Target')->placeholder('N/A'),
                        TextEntry::make('target_dn')->label('Target DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_action')->label('Action')->badge()->placeholder('N/A'),
                        TextEntry::make('display_status')->label('Status')->badge()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Payload')
                    ->schema([
                        TextEntry::make('input_payload')
                            ->label('Input Payload')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('output_payload')
                            ->label('Output Payload')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Execution')
                    ->schema([
                        TextEntry::make('attempt_count')->label('Attempts')->placeholder('0'),
                        TextEntry::make('payload_hash')->label('Payload Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_error_message')->label('Error Message')->placeholder('No error')->columnSpanFull(),
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->columns(array_values(array_filter([
                TextColumn::make('id')->label('ID')->sortable(),

                self::hasColumn('operation_job_items', 'operation_job_id')
                    ? TextColumn::make('operation_job_id')->label('Job ID')->sortable()
                    : null,

                self::hasColumn('operation_job_items', 'target_type')
                    ? TextColumn::make('target_type')->label('Target Type')->searchable()->sortable()
                    : null,

                self::hasColumn('operation_job_items', 'target_identifier')
                    ? TextColumn::make('display_target')->label('Target')->limit(45)
                    : null,

                self::hasColumn('operation_job_items', 'target_dn')
                    ? TextColumn::make('target_dn')->label('Target DN')->searchable()->limit(55)
                    : null,

                self::hasColumn('operation_job_items', 'action')
                    ? TextColumn::make('display_action')->label('Action')->badge()->color('warning')
                    : null,

                self::hasColumn('operation_job_items', 'status')
                    ? TextColumn::make('display_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'gray',
                        'running' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'gray',
                        'conflict' => 'warning',
                        'cancelled' => 'gray',
                        'already_applied' => 'success',
                        default => 'gray',
                    })
                    : null,

                self::hasColumn('operation_job_items', 'attempt_count')
                    ? TextColumn::make('attempt_count')->label('Attempts')->sortable()
                    : null,

                self::hasColumn('operation_job_items', 'error_message')
                    ? TextColumn::make('display_error_message')->label('Error')->limit(70)->placeholder('No error')
                    : null,

                self::hasColumn('operation_job_items', 'created_at')
                    ? TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)
                    : null,
            ])))
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                        'conflict' => 'Conflict',
                        'cancelled' => 'Cancelled',
                        'already_applied' => 'Already Applied',
                    ]),

                SelectFilter::make('action')
                    ->label('Action')
                    ->options([
                        'refresh_ldap_directory_cache' => 'Refresh LDAP Directory Cache',
                        'create' => 'Create',
                        'update' => 'Update',
                        'delete' => 'Delete',
                        'rename' => 'Rename',
                        'move' => 'Move',
                        'import' => 'Import',
                        'export' => 'Export',
                        'sync' => 'Sync',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            return DbSchema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationJobItems::route('/'),
            'view' => Pages\ViewOperationJobItem::route('/{record}'),
        ];
    }
}
