<?php

namespace App\Services\Operations;

use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobItem;
use App\Models\Operations\OperationJobLog;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OperationJobTracker
{
    public function create(array $data): OperationJob
    {
        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => 'queued',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'total_items' => 0,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'skipped_items' => 0,
            'conflict_items' => 0,
            'metadata' => [],
        ], $data);

        $payload = $this->filterForTable('operation_jobs', $payload);

        $job = new OperationJob();
        $job->forceFill($payload);
        $job->save();

        $this->log($job, 'info', 'Operation job created.', [
            'status' => $job->status ?? 'queued',
        ]);

        return $job;
    }

    public function createItem(OperationJob $job, array $data): ?OperationJobItem
    {
        if (! Schema::hasTable('operation_job_items')) {
            return null;
        }

        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'operation_job_id' => $job->id,
            'status' => 'pending',
            'attempt_count' => 0,
        ], $data);

        foreach (['input_payload', 'output_payload'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = RedactsSensitiveData::redact($payload[$field]);
            }
        }

        $payload = $this->filterForTable('operation_job_items', $payload);

        $item = new OperationJobItem();
        $item->forceFill($payload);
        $item->save();

        return $item;
    }

    public function markRunning(OperationJob $job): void
    {
        $this->updateJob($job, [
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->log($job, 'info', 'Operation job started.');
    }

    public function markSuccess(OperationJob $job, array $data = []): void
    {
        $this->updateJob($job, array_merge([
            'status' => 'success',
            'finished_at' => now(),
        ], $data));

        $this->log($job, 'info', 'Operation job completed successfully.', $data);
    }

    public function markFailed(OperationJob $job, string $errorMessage, array $data = []): void
    {
        $this->updateJob($job, array_merge([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $errorMessage,
        ], $data));

        $this->log($job, 'error', 'Operation job failed.', array_merge($data, [
            'error_message' => $errorMessage,
        ]));
    }

    public function updateJob(OperationJob $job, array $data): void
    {
        $payload = $this->filterForTable('operation_jobs', $data);

        if ($payload === []) {
            return;
        }

        $job->forceFill($payload);
        $job->save();
    }

    public function updateItem(?OperationJobItem $item, array $data): void
    {
        if (! $item) {
            return;
        }

        foreach (['input_payload', 'output_payload'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = RedactsSensitiveData::redact($data[$field]);
            }
        }

        $payload = $this->filterForTable('operation_job_items', $data);

        if ($payload === []) {
            return;
        }

        $item->forceFill($payload);
        $item->save();
    }

    public function log(OperationJob $job, string $level, string $message, array $context = [], ?OperationJobItem $item = null): ?OperationJobLog
    {
        if (! Schema::hasTable('operation_job_logs')) {
            return null;
        }

        try {
            $payload = [
                'uuid' => (string) Str::uuid(),
                'operation_job_id' => $job->id,
                'operation_job_item_id' => $item?->id,
                'level' => $level,
                'message' => $message,
                'context' => RedactsSensitiveData::redact($context),
                'created_at' => now(),
            ];

            $payload = $this->filterForTable('operation_job_logs', $payload);

            $log = new OperationJobLog();
            $log->forceFill($payload);
            $log->save();

            return $log;
        } catch (Throwable) {
            return null;
        }
    }

    private function filterForTable(string $table, array $data): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        return collect($data)
            ->only($columns)
            ->all();
    }
}
