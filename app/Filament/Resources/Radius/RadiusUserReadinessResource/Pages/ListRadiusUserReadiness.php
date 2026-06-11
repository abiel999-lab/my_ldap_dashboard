<?php

namespace App\Filament\Resources\Radius\RadiusUserReadinessResource\Pages;

use App\Filament\Resources\Radius\RadiusUserReadinessResource;
use App\Jobs\Radius\SyncWifiReadinessJob;
use App\Support\Operations\SafeCommandExecutionLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRadiusUserReadiness extends ListRecords
{
    protected static string $resource = RadiusUserReadinessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_wifi_readiness')
                ->label('Sync WiFi Readiness')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync WiFi Readiness')
                ->modalDescription('This will queue a verification job. It will not modify LDAP. It verifies the current PostgreSQL LDAP mirror and writes READY / PARTIAL / FAILED into Command Executions.')
                ->action(function (): void {
                    $execution = SafeCommandExecutionLogger::createQueued(
                        'wifi_readiness_sync',
                        'Sync WiFi Readiness from Filament UI',
                        [
                            'operation' => 'wifi_readiness_sync',
                            'source' => 'WiFi Readiness header action',
                            'queue' => 'ldap',
                            'destructive' => false,
                        ],
                    );

                    SyncWifiReadinessJob::dispatch(SafeCommandExecutionLogger::id($execution));

                    Notification::make()
                        ->title('WiFi Readiness sync queued')
                        ->body($execution ? ('Command Execution ID: ' . $execution->id) : 'Job queued.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
