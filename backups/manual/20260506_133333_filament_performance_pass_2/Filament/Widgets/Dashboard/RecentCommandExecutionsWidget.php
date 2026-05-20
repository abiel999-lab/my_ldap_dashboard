<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Operations\CommandExecution;
use Filament\Tables\Columns\IconColumn;
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
                    ->latest('id')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('id')->label('ID'),
                TextColumn::make('command_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('display_command')->label('Command')->limit(70),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'blocked' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('safe_mode')->label('Safe')->boolean(),
                TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('N/A'),
                TextColumn::make('created_at')->label('Created')->dateTime(),
            ])
            ->paginated(false);
    }
}
