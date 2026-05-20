<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Operations\OperationJob;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentOperationJobsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Operation Jobs';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OperationJob::query()
                    ->latest('id')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('display_name')
                    ->label('Name')
                    ->weight('semibold')
                    ->limit(45),

                TextColumn::make('display_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->color('gray')
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
                    ->placeholder('N/A'),

                TextColumn::make('progress_percent')
                    ->label('Progress')
                    ->suffix('%'),

                TextColumn::make('display_target_dn')
                    ->label('Target DN')
                    ->limit(45)
                    ->placeholder('N/A'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('N/A'),
            ])
            ->paginated(false);
    }
}
