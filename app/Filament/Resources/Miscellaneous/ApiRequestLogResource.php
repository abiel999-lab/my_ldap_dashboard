<?php

namespace App\Filament\Resources\Miscellaneous;

use App\Filament\Resources\Miscellaneous\ApiRequestLogResource\Pages;
use App\Models\Api\ApiRequestLog;
use BackedEnum;
use Filament\Actions\Action as TableAction;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class ApiRequestLogResource extends Resource
{
    protected static ?string $model = ApiRequestLog::class;

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'API Request Logs';

    protected static ?string $modelLabel = 'API Request Log';

    protected static ?string $pluralModelLabel = 'API Request Logs';

    protected static ?string $slug = 'miscellaneous/api-request-logs';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('api_client_name')
                    ->label('Client')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->sortable(),

                TextColumn::make('path')
                    ->label('Path')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->placeholder('-'),

                TextColumn::make('status_code')
                    ->label('HTTP')
                    ->badge()
                    ->sortable(),

                IconColumn::make('ok')
                    ->label('OK')
                    ->boolean(),

                TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('duration_ms')
                    ->label('Ms')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                TableAction::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete API Request Log')
                    ->modalDescription('Log API yang dipilih akan dihapus.')
                    ->action(function (ApiRequestLog $record): void {
                        $record->delete();

                        Notification::make()
                            ->title('API request log deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulk_delete')
                    ->label('Delete Selected Logs')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Selected API Request Logs')
                    ->modalDescription('Semua API request logs yang dipilih akan dihapus.')
                    ->action(function (Collection $records): void {
                        $records->each(fn (ApiRequestLog $record): ?bool => $record->delete());

                        Notification::make()
                            ->title('Selected API request logs deleted')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiRequestLogs::route('/'),
        ];
    }
}
