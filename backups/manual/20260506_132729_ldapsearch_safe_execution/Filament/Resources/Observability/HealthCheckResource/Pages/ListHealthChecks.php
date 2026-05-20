<?php

namespace App\Filament\Resources\Observability\HealthCheckResource\Pages;

use App\Filament\Resources\Observability\HealthCheckResource;
use App\Services\Audit\AuditLogger;
use App\Services\Observability\HealthCheckService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHealthChecks extends ListRecords
{
    protected static string $resource = HealthCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runHealthChecks')
                ->label('Run Health Checks')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Run health checks?')
                ->modalDescription('This will check database, queue, storage, logs, and active LDAP connections.')
                ->action(function (): void {
                    $startedAt = microtime(true);

                    $checks = app(HealthCheckService::class)->runAll();

                    $failed = collect($checks)->where('status', 'failed')->count();
                    $warning = collect($checks)->where('status', 'warning')->count();
                    $healthy = collect($checks)->where('status', 'healthy')->count();

                    app(AuditLogger::class)->log([
                        'module' => 'observability.health_checks',
                        'action' => 'run_health_checks',
                        'status' => $failed > 0 ? 'failed' : 'success',
                        'request_payload' => [
                            'components' => ['database', 'queue', 'storage', 'logs', 'ldap'],
                        ],
                        'after_value' => [
                            'healthy' => $healthy,
                            'warning' => $warning,
                            'failed' => $failed,
                            'total' => count($checks),
                        ],
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]);

                    Notification::make()
                        ->title('Health checks completed')
                        ->body("Healthy: {$healthy}, Warning: {$warning}, Failed: {$failed}.")
                        ->{$failed > 0 ? 'danger' : ($warning > 0 ? 'warning' : 'success')}()
                        ->send();
                }),
        ];
    }
}
