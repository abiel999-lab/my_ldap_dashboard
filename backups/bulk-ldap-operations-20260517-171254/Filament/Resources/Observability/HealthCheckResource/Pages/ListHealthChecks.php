<?php

namespace App\Filament\Resources\Observability\HealthCheckResource\Pages;

use App\Filament\Resources\Observability\HealthCheckResource;
use App\Services\Audit\AuditLogger;
use App\Services\Observability\HealthCheckService;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

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

                    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

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
                        'duration_ms' => $durationMs,
                    ]);

                    app(UnifiedActivityLogger::class)->{$failed > 0 ? 'failed' : 'success'}(
                        module: 'observability.health_checks',
                        action: 'run_health_checks',
                        message: 'Health checks completed from UI. Healthy: '.$healthy.', Warning: '.$warning.', Failed: '.$failed.'.',
                        context: [
                            'operation_type' => 'health_check',
                            'event' => 'run_health_checks',
                            'target_type' => 'health_check',
                            'target_id' => 'health_checks',
                            'target_label' => 'Health Checks',
                            'source' => 'filament',
                            'components' => ['database', 'queue', 'storage', 'logs', 'ldap'],
                            'healthy' => $healthy,
                            'warning' => $warning,
                            'failed_count' => $failed,
                            'duration_ms' => $durationMs,
                            'total' => count($checks),
                            'success' => $failed > 0 ? 0 : 1,
                            'failed' => $failed > 0 ? 1 : 0,
                            'skipped' => 0,
                        ],
                    );

                    Notification::make()
                        ->title('Health checks completed')
                        ->body("Healthy: {$healthy}, Warning: {$warning}, Failed: {$failed}.")
                        ->{$failed > 0 ? 'danger' : ($warning > 0 ? 'warning' : 'success')}()
                        ->send();
                }),
        ];
    }

    public function mount(): void
    {
        parent::mount();

        try {
            app(UnifiedActivityLogger::class)->info(
                module: 'observability.health_checks',
                action: 'view_health_checks_list',
                message: 'Health Checks list opened.',
                context: [
                    'operation_type' => 'health_check',
                    'event' => 'view_health_checks_list',
                    'target_type' => 'health_check',
                    'target_id' => 'health_checks_list',
                    'target_label' => 'Health Checks List',
                    'source' => 'filament',
                    'total' => 1,
                    'success' => 1,
                    'failed' => 0,
                    'skipped' => 0,
                ],
            );
        } catch (Throwable) {
            // Logging must never break page rendering.
        }
    }


}
