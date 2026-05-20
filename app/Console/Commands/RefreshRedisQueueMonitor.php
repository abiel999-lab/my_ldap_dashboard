<?php

namespace App\Console\Commands;

use App\Models\Operations\OperationJob;
use App\Models\Operations\QueueMonitorJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RefreshRedisQueueMonitor extends Command
{
    protected $signature = 'queue:monitor-refresh
        {--queues=export,import,schema,operations,default,ldap_schema_sync_queued,ldap_users_sync_all_queued,ldif_export : Comma separated queue names}';

    protected $description = 'Refresh Redis queue monitor snapshot and running Operation Jobs into queue_monitor_jobs table.';

    public function handle(): int
    {
        $queues = collect(explode(',', (string) $this->option('queues')))
            ->map(fn (string $queue): string => trim($queue))
            ->filter()
            ->unique()
            ->values();

        QueueMonitorJob::query()->delete();

        $total = 0;

        foreach ($queues as $queue) {
            $total += $this->snapshotPending($queue);
            $total += $this->snapshotSortedSet($queue, 'delayed');
            $total += $this->snapshotSortedSet($queue, 'reserved');
        }

        $total += $this->snapshotOperationJobs();

        $this->info("Queue monitor refreshed. {$total} queue/operation jobs detected.");

        return self::SUCCESS;
    }

    private function snapshotPending(string $queue): int
    {
        $key = "queues:{$queue}";
        $items = Redis::connection()->lrange($key, 0, 1000);

        $count = 0;

        foreach ($items as $rawPayload) {
            $this->storePayload($queue, 'redis_pending', (string) $rawPayload, null);
            $count++;
        }

        return $count;
    }

    private function snapshotSortedSet(string $queue, string $status): int
    {
        $key = "queues:{$queue}:{$status}";
        $items = Redis::connection()->zrange($key, 0, 1000, ['withscores' => true]);

        $count = 0;

        foreach ($items as $rawPayload => $score) {
            $this->storePayload($queue, 'redis_'.$status, (string) $rawPayload, (int) $score);
            $count++;
        }

        return $count;
    }

    private function snapshotOperationJobs(): int
    {
        $jobs = OperationJob::query()
            ->whereIn('status', ['queued', 'running', 'processing'])
            ->latest('id')
            ->limit(200)
            ->get();

        foreach ($jobs as $job) {
            QueueMonitorJob::query()->create([
                'queue' => (string) ($job->source ?: $job->operation_type ?: 'operation'),
                'redis_status' => 'operation_'.$job->status,
                'job_uuid' => (string) ($job->uuid ?? $job->id),
                'job_class' => (string) ($job->name ?: $job->display_name ?: 'Operation Job #'.$job->id),
                'attempts' => 0,
                'available_at' => $job->created_at,
                'reserved_at' => $job->started_at,
                'payload_hash' => hash('sha256', 'operation-job-'.$job->id.'-'.$job->updated_at),
                'payload' => [
                    'source' => 'operation_jobs',
                    'operation_job_id' => $job->id,
                    'uuid' => $job->uuid,
                    'status' => $job->status,
                    'name' => $job->name ?? null,
                    'operation_type' => $job->operation_type ?? null,
                    'operation_action' => $job->operation_action ?? null,
                    'target_dn' => $job->target_dn ?? null,
                    'total_items' => $job->total_items ?? null,
                    'processed_items' => $job->processed_items ?? null,
                    'success_items' => $job->success_items ?? null,
                    'failed_items' => $job->failed_items ?? null,
                    'created_at' => $job->created_at?->toDateTimeString(),
                    'started_at' => $job->started_at?->toDateTimeString(),
                    'updated_at' => $job->updated_at?->toDateTimeString(),
                    'metadata' => $job->metadata,
                ],
            ]);
        }

        return $jobs->count();
    }

    private function storePayload(string $queue, string $status, string $rawPayload, ?int $score): void
    {
        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $payload = [
                'raw' => $rawPayload,
            ];
        }

        $jobClass = $payload['displayName']
            ?? $payload['data']['commandName']
            ?? $payload['job']
            ?? 'Unknown Job';

        QueueMonitorJob::query()->create([
            'queue' => $queue,
            'redis_status' => $status,
            'job_uuid' => $payload['uuid'] ?? $payload['id'] ?? null,
            'job_class' => is_string($jobClass) ? $jobClass : 'Unknown Job',
            'attempts' => (int) ($payload['attempts'] ?? 0),
            'available_at' => str_contains($status, 'delayed') && $score ? now()->setTimestamp($score) : null,
            'reserved_at' => str_contains($status, 'reserved') && $score ? now()->setTimestamp($score) : null,
            'payload_hash' => hash('sha256', $rawPayload),
            'payload' => $payload,
        ]);
    }
}
