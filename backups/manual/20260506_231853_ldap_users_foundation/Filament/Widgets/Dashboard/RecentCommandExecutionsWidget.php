<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Operations\CommandExecution;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentCommandExecutionsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Command Executions';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CommandExecution::query()
                    ->select([
                        'id',
                        'command_type',
                        'command',
                        'status',
                        'exit_code',
                        'duration_ms',
                        'created_at',
                    ])
                    ->latest('id')
                    ->limit(4)
            )
            ->columns([
                TextColumn::make('id')->label('ID'),

                TextColumn::make('command_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('display_command')
                    ->label('Command')
                    ->limit(45),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'previewed' => 'info',
                        'failed' => 'danger',
                        'blocked' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('exit_code')
                    ->label('Exit')
                    ->placeholder('N/A'),

                TextColumn::make('duration_ms')
                    ->label('Ms')
                    ->placeholder('N/A'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
            ])
            ->paginated(false);
    }
}
