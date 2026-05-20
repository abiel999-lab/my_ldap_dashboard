<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\QueueJobResource\Pages;
use App\Models\Operations\QueueMonitorJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

class QueueJobResource extends Resource
{
    protected static ?string $model = QueueMonitorJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'Queue Jobs';

    protected static ?string $modelLabel = 'Queue Job';

    protected static ?string $pluralModelLabel = 'Queue Jobs';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Queue Job')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('queue')->label('Queue')->badge(),
                        TextEntry::make('redis_status')->label('Status')->badge(),
                        TextEntry::make('job_class')->label('Job Class')->columnSpanFull(),
                        TextEntry::make('job_uuid')->label('Job UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('attempts')->label('Attempts'),
                        TextEntry::make('available_at')->label('Available At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('reserved_at')->label('Reserved At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('payload_hash')->label('Payload Hash')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Payload')
                    ->schema([
                        KeyValueEntry::make('payload')
                            ->label('Payload')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('refreshRedisQueue')
                    ->label('Refresh Redis Queue')
                    ->icon(Heroicon::ArrowPath)
                    ->color('primary')
                    ->action(function (): void {
                        Artisan::call('queue:monitor-refresh');

                        Notification::make()
                            ->title('Queue monitor refreshed')
                            ->body(trim(Artisan::output()) ?: 'Redis queue snapshot updated.')
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('queue')
                    ->label('Queue')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('redis_status')
                    ->label('Redis Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'reserved' => 'info',
                        'delayed' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('job_class')
                    ->label('Job')
                    ->searchable()
                    ->limit(70),

                TextColumn::make('attempts')
                    ->label('Attempts')
                    ->sortable(),

                TextColumn::make('available_at')
                    ->label('Available At')
                    ->dateTime()
                    ->placeholder('N/A'),

                TextColumn::make('reserved_at')
                    ->label('Reserved At')
                    ->dateTime()
                    ->placeholder('N/A'),

                TextColumn::make('updated_at')
                    ->label('Snapshot At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->label('Queue')
                    ->options([
                        'export' => 'export',
                        'import' => 'import',
                        'schema' => 'schema',
                        'operations' => 'operations',
                        'default' => 'default',
                    ]),

                SelectFilter::make('redis_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'reserved' => 'Reserved',
                        'delayed' => 'Delayed',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No Redis queue jobs detected')
            ->emptyStateDescription('Click Refresh Redis Queue. If still empty, jobs are already completed or workers processed them immediately.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQueueJobs::route('/'),
            'view' => Pages\ViewQueueJob::route('/{record}'),
        ];
    }
}
