<?php

namespace App\Filament\Resources\Operations\QueueJobResource\Pages;

use App\Filament\Resources\Operations\QueueJobResource;
use App\Services\Observability\UnifiedActivityLogger;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListQueueJobs extends ListRecords
{
    protected static string $resource = QueueJobResource::class;

    public function mount(): void
    {
        parent::mount();

        try {
            app(UnifiedActivityLogger::class)->info(
                module: 'operations.queue_jobs',
                action: 'view_queue_jobs_list',
                message: 'Queue Jobs list opened.',
                context: [
                    'operation_type' => 'queue_job',
                    'event' => 'view_queue_jobs_list',
                    'target_type' => 'queue_monitor',
                    'target_id' => 'queue_jobs_list',
                    'target_label' => 'Queue Jobs List',
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
