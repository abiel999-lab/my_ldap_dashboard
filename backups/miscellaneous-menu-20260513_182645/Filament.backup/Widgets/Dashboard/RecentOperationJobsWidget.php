<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Operations\OperationJob;

class RecentOperationJobsWidget extends BaseWidget
{
    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Operation Jobs')
            ->query(fn (): Builder => OperationJob::query()->latest('id')->limit(10))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->limit(42)
                    ->searchable(),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->limit(28),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->limit(28)
                    ->placeholder('N/A'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'running' => 'info',
                        'queued' => 'warning',
                        'failed' => 'danger',
                        'partial_success' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_items')
                    ->label('Total'),

                TextColumn::make('success_items')
                    ->label('OK'),

                TextColumn::make('failed_items')
                    ->label('Fail'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
