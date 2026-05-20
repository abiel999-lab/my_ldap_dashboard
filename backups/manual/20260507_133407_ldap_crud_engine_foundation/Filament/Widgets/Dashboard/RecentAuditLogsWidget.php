<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Audit\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentAuditLogsWidget extends BaseWidget
{
    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Audit Activities')
            ->query(fn (): Builder => AuditLog::query()->latest('id')->limit(10))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->limit(32),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->limit(36),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'blocked' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('actor_email')
                    ->label('Actor')
                    ->limit(34)
                    ->placeholder('System'),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->limit(48)
                    ->placeholder('N/A'),

                TextColumn::make('duration_ms')
                    ->label('Ms')
                    ->placeholder('N/A'),

                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
