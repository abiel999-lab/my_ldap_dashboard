<?php

namespace App\Filament\Resources\Observability\HealthCheckResource\Pages;

use App\Filament\Resources\Observability\HealthCheckResource;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewHealthCheck extends ViewRecord
{
    protected static string $resource = HealthCheckResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        try {
            $healthCheck = $this->getRecord();

            app(UnifiedActivityLogger::class)->info(
                module: 'observability.health_checks',
                action: 'view_health_check',
                message: 'Health Check detail opened.',
                context: [
                    'operation_type' => 'health_check',
                    'event' => 'view_health_check',
                    'target_type' => 'health_check',
                    'target_id' => $healthCheck?->getKey(),
                    'target_label' => ($healthCheck?->component ?? 'health').'/'.($healthCheck?->name ?? 'unknown'),
                    'component' => $healthCheck?->component ?? null,
                    'name' => $healthCheck?->name ?? null,
                    'health_status' => $healthCheck?->status ?? null,
                    'duration_ms' => $healthCheck?->duration_ms ?? null,
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
