<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Audit\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentAuditLogsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Audit Activities';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AuditLog::query()
                    ->latest('id')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->searchable(),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('actor_email')
                    ->label('Actor')
                    ->placeholder('System'),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->limit(55)
                    ->placeholder('N/A'),
            ])
            ->paginated(false);
    }
}
