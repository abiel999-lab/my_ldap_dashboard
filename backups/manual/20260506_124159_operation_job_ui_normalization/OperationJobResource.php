<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\OperationJobResource\Pages;
use App\Models\Operations\OperationJob;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use UnitEnum;

class OperationJobResource extends Resource
{
    protected static ?string $model = OperationJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Operation Jobs';

    protected static ?string $modelLabel = 'Operation Job';

    protected static ?string $pluralModelLabel = 'Operation Jobs';

    protected static ?int $navigationSort = 10;

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
                Section::make('Operation')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_name')->label('Name'),
                        TextEntry::make('type')->label('Type')->badge()->placeholder('N/A'),
                        TextEntry::make('module')->label('Module')->badge()->placeholder('N/A'),
                        TextEntry::make('action')->label('Action')->badge()->placeholder('N/A'),
                        TextEntry::make('status')->label('Status')->badge()->placeholder('N/A'),
                        TextEntry::make('source')->label('Source')->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Target')
                    ->schema([
                        TextEntry::make('target_type')->label('Target Type')->placeholder('N/A'),
                        TextEntry::make('target_key')->label('Target Key')->placeholder('N/A'),
                        TextEntry::make('target_dn')->label('Target DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('ldap_connection_id')->label('LDAP Connection ID')->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Progress')
                    ->schema([
                        TextEntry::make('total_items')->label('Total')->placeholder('0'),
                        TextEntry::make('processed_items')->label('Processed')->placeholder('0'),
                        TextEntry::make('success_items')->label('Success')->placeholder('0'),
                        TextEntry::make('failed_items')->label('Failed')->placeholder('0'),
                        TextEntry::make('skipped_items')->label('Skipped')->placeholder('0'),
                        TextEntry::make('conflict_items')->label('Conflicts')->placeholder('0'),
                        TextEntry::make('progress_percent')->label('Progress')->suffix('%'),
                    ])
                    ->columns(4),

                Section::make('Error / Metadata')
                    ->schema([
                        TextEntry::make('error_message')->label('Error Message')->placeholder('No error')->columnSpanFull(),
                        TextEntry::make('metadata')
                            ->label('Metadata')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('created_at')->label('Created At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('id'))
            ->columns(array_values(array_filter([
                TextColumn::make('id')->label('ID')->sortable(),

                self::hasColumn('operation_jobs', 'name')
                    ? TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('semibold')
                    : null,

                self::hasColumn('operation_jobs', 'type')
                    ? TextColumn::make('type')->label('Type')->badge()->searchable()->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'module')
                    ? TextColumn::make('module')->label('Module')->badge()->searchable()->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'action')
                    ? TextColumn::make('action')->label('Action')->badge()->searchable()->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'status')
                    ? TextColumn::make('status')->label('Status')->badge()->searchable()->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'source')
                    ? TextColumn::make('source')->label('Source')->searchable()->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'total_items')
                    ? TextColumn::make('total_items')->label('Total')->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'processed_items')
                    ? TextColumn::make('processed_items')->label('Processed')->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'failed_items')
                    ? TextColumn::make('failed_items')->label('Failed')->sortable()
                    : null,

                self::hasColumn('operation_jobs', 'started_at')
                    ? TextColumn::make('started_at')->label('Started')->dateTime()->sortable()->placeholder('N/A')
                    : null,

                self::hasColumn('operation_jobs', 'finished_at')
                    ? TextColumn::make('finished_at')->label('Finished')->dateTime()->sortable()->placeholder('N/A')
                    : null,

                self::hasColumn('operation_jobs', 'created_at')
                    ? TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)
                    : null,
            ])))
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
            'index' => Pages\ListOperationJobs::route('/'),
            'view' => Pages\ViewOperationJob::route('/{record}'),
        ];
    }
}
