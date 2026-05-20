<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\OperationJobResource\Pages;
use App\Filament\Resources\Operations\OperationJobResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Operations\OperationJobResource\RelationManagers\LogsRelationManager;
use App\Models\Operations\OperationJob;
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
                        TextEntry::make('display_type')->label('Type')->badge(),
                        TextEntry::make('module')->label('Module')->badge()->placeholder('N/A'),
                        TextEntry::make('display_action')->label('Action')->badge(),
                        TextEntry::make('status')->label('Status')->badge()->placeholder('N/A'),
                        TextEntry::make('display_source')->label('Source'),
                    ])
                    ->columns(2),

                Section::make('Target')
                    ->schema([
                        TextEntry::make('display_target_type')->label('Target Type'),
                        TextEntry::make('display_target_key')->label('Target Key'),
                        TextEntry::make('display_target_dn')->label('Target DN')->columnSpanFull(),
                        TextEntry::make('display_ldap_connection_id')->label('LDAP Connection ID'),
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
                        TextEntry::make('display_error_message')->label('Error Message')->columnSpanFull(),
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
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('display_name')
                    ->label('Name')
                    ->weight('semibold')
                    ->limit(50),

                TextColumn::make('display_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('display_action')
                    ->label('Action')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'previewed' => 'info',
                        'pending' => 'gray',
                        'queued' => 'warning',
                        'running' => 'info',
                        'paused' => 'warning',
                        'success' => 'success',
                        'partial_success' => 'warning',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        'rolled_back' => 'gray',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('display_source')
                    ->label('Source')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('total_items')
                    ->label('Total')
                    ->sortable()
                    ->placeholder('0'),

                TextColumn::make('processed_items')
                    ->label('Processed')
                    ->sortable()
                    ->placeholder('0'),

                TextColumn::make('failed_items')
                    ->label('Failed')
                    ->sortable()
                    ->placeholder('0'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'previewed' => 'Previewed',
                        'pending' => 'Pending',
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'paused' => 'Paused',
                        'success' => 'Success',
                        'partial_success' => 'Partial Success',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                        'rolled_back' => 'Rolled Back',
                    ]),

                SelectFilter::make('operation_type')
                    ->label('Type')
                    ->options([
                        'ldap_directory_cache_refresh' => 'LDAP Directory Cache Refresh',
                        'ldap_search' => 'LDAP Search',
                        'ldap_schema_test' => 'LDAP Schema Test',
                        'import' => 'Import',
                        'export' => 'Export',
                        'sync' => 'Sync',
                        'script' => 'Script',
                        'backup' => 'Backup',
                        'restore' => 'Restore',
                    ]),

                SelectFilter::make('module')
                    ->label('Module')
                    ->options([
                        'directory.ldap_browser' => 'Directory Browser',
                        'directory.ldap_connections' => 'LDAP Connections',
                        'operations.import' => 'Imports',
                        'operations.export' => 'Exports',
                        'operations.sync' => 'Sync Center',
                        'operations.script' => 'Script Engine',
                        'maintenance.backup' => 'Backup',
                        'maintenance.restore' => 'Restore',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationJobs::route('/'),
            'view' => Pages\ViewOperationJob::route('/{record}'),
        ];
    }
}
