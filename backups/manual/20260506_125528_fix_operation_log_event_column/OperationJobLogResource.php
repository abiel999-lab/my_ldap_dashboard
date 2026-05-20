<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\OperationJobLogResource\Pages;
use App\Models\Operations\OperationJobLog;
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

class OperationJobLogResource extends Resource
{
    protected static ?string $model = OperationJobLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Operation Job Logs';

    protected static ?string $modelLabel = 'Operation Job Log';

    protected static ?string $pluralModelLabel = 'Operation Job Logs';

    protected static ?int $navigationSort = 12;

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
                Section::make('Log')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('operation_job_item_id')->label('Operation Job Item ID')->placeholder('N/A'),
                        TextEntry::make('level')->label('Level')->badge()->placeholder('info'),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Created At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Context')
                    ->schema([
                        TextEntry::make('context')
                            ->label('Context')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('id'))
            ->columns(array_values(array_filter([
                TextColumn::make('id')->label('ID')->sortable(),

                self::hasColumn('operation_job_logs', 'operation_job_id')
                    ? TextColumn::make('operation_job_id')->label('Job ID')->sortable()
                    : null,

                self::hasColumn('operation_job_logs', 'operation_job_item_id')
                    ? TextColumn::make('operation_job_item_id')->label('Item ID')->sortable()->placeholder('N/A')
                    : null,

                self::hasColumn('operation_job_logs', 'level')
                    ? TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'debug' => 'gray',
                        'info' => 'info',
                        'notice' => 'info',
                        'warning' => 'warning',
                        'error' => 'danger',
                        'critical' => 'danger',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable()
                    : null,

                self::hasColumn('operation_job_logs', 'message')
                    ? TextColumn::make('message')->label('Message')->searchable()->limit(90)
                    : null,

                self::hasColumn('operation_job_logs', 'created_at')
                    ? TextColumn::make('created_at')->label('Created')->dateTime()->sortable()
                    : null,
            ])))
            ->filters([
                SelectFilter::make('level')
                    ->label('Level')
                    ->options([
                        'debug' => 'Debug',
                        'info' => 'Info',
                        'notice' => 'Notice',
                        'warning' => 'Warning',
                        'error' => 'Error',
                        'critical' => 'Critical',
                    ]),
            ])
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
            'index' => Pages\ListOperationJobLogs::route('/'),
            'view' => Pages\ViewOperationJobLog::route('/{record}'),
        ];
    }
}
