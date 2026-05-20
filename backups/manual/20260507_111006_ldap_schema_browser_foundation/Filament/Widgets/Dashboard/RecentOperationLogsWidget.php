<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Operations\OperationJobLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentOperationLogsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Operation Logs';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OperationJobLog::query()
                    ->latest('id')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('operation_job_id')
                    ->label('Job ID')
                    ->sortable(),

                TextColumn::make('operation_job_item_id')
                    ->label('Item ID')
                    ->placeholder('N/A'),

                TextColumn::make('level')
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
                    }),

                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color('gray')
                    ->limit(35),

                TextColumn::make('message')
                    ->label('Message')
                    ->limit(75),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
