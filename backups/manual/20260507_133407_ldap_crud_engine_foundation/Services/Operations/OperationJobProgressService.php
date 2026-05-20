<?php

namespace App\Services\Operations;

use App\Models\Operations\OperationJob;
use Illuminate\Support\Facades\Schema;

class OperationJobProgressService
{
    public function recalculate(OperationJob $job): OperationJob
    {
        $total = (int) ($job->total_items ?? 0);
        $success = (int) ($job->success_items ?? 0);
        $failed = (int) ($job->failed_items ?? 0);
        $skipped = (int) ($job->skipped_items ?? 0);
        $conflict = (int) ($job->conflict_items ?? 0);
        $running = (int) ($job->running_items ?? 0);
        $pending = (int) ($job->pending_items ?? 0);

        $processed = $success + $failed + $skipped + $conflict;

        if ($total <= 0) {
            $total = max($processed + $running + $pending, 1);
        }

        $percent = (int) round(($processed / max($total, 1)) * 100);
        $percent = max(0, min(100, $percent));

        $payload = [];

        if (Schema::hasColumn('operation_jobs', 'processed_items')) {
            $payload['processed_items'] = $processed;
        }

        if (Schema::hasColumn('operation_jobs', 'progress_percent')) {
            $payload['progress_percent'] = $percent;
        }

        if ($payload !== []) {
            $job->forceFill($payload)->save();
            $job->refresh();
        }

        return $job;
    }

    public function progressText(OperationJob $job): string
    {
        $total = (int) ($job->total_items ?? 0);
        $processed = (int) ($job->processed_items ?? 0);

        if ($processed <= 0) {
            $processed = (int) ($job->success_items ?? 0)
                + (int) ($job->failed_items ?? 0)
                + (int) ($job->skipped_items ?? 0)
                + (int) ($job->conflict_items ?? 0);
        }

        $percent = (int) ($job->progress_percent ?? 0);

        if ($total <= 0) {
            return $percent.'%';
        }

        return $percent.'% · '.$processed.'/'.$total;
    }

    public function progressPercent(OperationJob $job): int
    {
        if ($job->progress_percent !== null) {
            return (int) $job->progress_percent;
        }

        $total = max((int) ($job->total_items ?? 0), 1);
        $processed = (int) ($job->processed_items ?? 0);

        if ($processed <= 0) {
            $processed = (int) ($job->success_items ?? 0)
                + (int) ($job->failed_items ?? 0)
                + (int) ($job->skipped_items ?? 0)
                + (int) ($job->conflict_items ?? 0);
        }

        return max(0, min(100, (int) round(($processed / $total) * 100)));
    }
}
