<?php

namespace App\Filament\Resources\Miscellaneous;

use App\Filament\Resources\Miscellaneous\ApiClientResource\Pages;
use App\Models\Api\ApiClient;
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

class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'API Center';

    protected static ?string $modelLabel = 'API Client';

    protected static ?string $pluralModelLabel = 'API Center';

    protected static ?string $slug = 'miscellaneous/api-center';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

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

                TextColumn::make('name')
                    ->label('Client Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key_prefix')
                    ->label('Key Prefix')
                    ->badge()
                    ->copyable()
                    ->searchable(),

                TextColumn::make('scopes')
                    ->label('Scopes')
                    ->badge()
                    ->state(function (ApiClient $record): string {
                        $scopes = $record->scopes ?? [];

                        return $scopes === [] ? '-' : implode(', ', $scopes);
                    })
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('last_used_ip')
                    ->label('Last IP')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                TableAction::make('enable')
                    ->label('Enable')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ApiClient $record): bool => ! $record->is_active)
                    ->action(function (ApiClient $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->title('API client enabled')
                            ->success()
                            ->send();
                    }),

                TableAction::make('disable')
                    ->label('Disable')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ApiClient $record): bool => $record->is_active)
                    ->action(function (ApiClient $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title('API client disabled')
                            ->success()
                            ->send();
                    }),

                TableAction::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete API Client')
                    ->modalDescription('API client akan dihapus. API key yang terkait tidak dapat digunakan lagi.')
                    ->action(function (ApiClient $record): void {
                        $record->delete();

                        Notification::make()
                            ->title('API client deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulk_enable')
                    ->label('Enable Selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each(fn (ApiClient $record): bool => $record->update(['is_active' => true]));

                        Notification::make()
                            ->title('Selected API clients enabled')
                            ->success()
                            ->send();
                    }),

                BulkAction::make('bulk_disable')
                    ->label('Disable Selected')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each(fn (ApiClient $record): bool => $record->update(['is_active' => false]));

                        Notification::make()
                            ->title('Selected API clients disabled')
                            ->success()
                            ->send();
                    }),

                BulkAction::make('bulk_delete')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Selected API Clients')
                    ->modalDescription('Semua API client yang dipilih akan dihapus.')
                    ->action(function (Collection $records): void {
                        $records->each(fn (ApiClient $record): ?bool => $record->delete());

                        Notification::make()
                            ->title('Selected API clients deleted')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiClients::route('/'),
        ];
    }
}
