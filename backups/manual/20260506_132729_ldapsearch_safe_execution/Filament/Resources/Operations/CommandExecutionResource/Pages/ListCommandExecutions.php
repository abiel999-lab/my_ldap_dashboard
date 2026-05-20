<?php

namespace App\Filament\Resources\Operations\CommandExecutionResource\Pages;

use App\Filament\Resources\Operations\CommandExecutionResource;
use App\Services\Operations\SafeCommandRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCommandExecutions extends ListRecords
{
    protected static string $resource = CommandExecutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runSafeCommand')
                ->label('Run Safe Command')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->schema([
                    Select::make('command')
                        ->label('Allowed Command')
                        ->options(fn (): array => collect(app(SafeCommandRunner::class)->allowedCommands())
                            ->mapWithKeys(fn (string $command): array => [$command => $command])
                            ->all())
                        ->required(),
                ])
                ->modalHeading('Run safe command?')
                ->modalDescription('Only allowlisted non-destructive commands can run here.')
                ->action(function (array $data): void {
                    $execution = app(SafeCommandRunner::class)->run(
                        command: $data['command'],
                        commandType: 'safe_artisan',
                    );

                    Notification::make()
                        ->title($execution->status === 'success' ? 'Command executed' : 'Command failed or blocked')
                        ->body('Command Execution #'.$execution->id.' finished with status: '.$execution->status)
                        ->{$execution->status === 'success' ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }
}
