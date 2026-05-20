<?php

namespace App\Filament\Resources\Operations\QueueJobResource\Pages;

use App\Filament\Resources\Operations\QueueJobResource;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewQueueJob extends ViewRecord
{
    protected static string $resource = QueueJobResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        try {
            $queueJob = $this->getRecord();

            app(UnifiedActivityLogger::class)->info(
                module: 'operations.queue_jobs',
                action: 'view_queue_job',
                message: 'Queue Job detail opened.',
                context: [
                    'operation_type' => 'queue_job',
                    'event' => 'view_queue_job',
                    'target_type' => 'queue_monitor_job',
                    'target_id' => $queueJob?->getKey(),
                    'target_label' => $queueJob?->job_class ?? $queueJob?->queue ?? null,
                    'queue' => $queueJob?->queue ?? null,
                    'redis_status' => $queueJob?->redis_status ?? null,
                    'job_class' => $queueJob?->job_class ?? null,
                    'job_uuid' => $queueJob?->job_uuid ?? null,
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
