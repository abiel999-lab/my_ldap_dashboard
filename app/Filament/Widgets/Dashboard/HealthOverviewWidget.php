<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Observability\HealthCheck;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class HealthOverviewWidget extends TableWidget
{
    protected static ?string $heading = 'System Health Overview';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                HealthCheck::query()
                    ->orderBy('component')
                    ->orderBy('name')
                    ->limit(6)
            )
            ->columns([
                TextColumn::make('component')
                    ->label('Component')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('semibold'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('message')
                    ->label('Message')
                    ->limit(70),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms')
                    ->placeholder('N/A'),

                TextColumn::make('checked_at')
                    ->label('Checked At')
                    ->dateTime()
                    ->placeholder('Never'),
            ])
            ->paginated(false);
    }
}
