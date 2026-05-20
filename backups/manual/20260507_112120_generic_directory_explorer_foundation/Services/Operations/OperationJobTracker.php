<?php

namespace App\Services\Operations;

use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobItem;
use App\Models\Operations\OperationJobLog;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OperationJobTracker
{
    public function create(array $data): OperationJob
    {
        $data = $this->normalizeOperationJobData($data);

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

        $data = $this->normalizeOperationJobItemData($data);

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
        $data = $this->normalizeOperationJobData($data);
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

        $data = $this->normalizeOperationJobItemData($data);

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
            Log::warning('operation_job_logs table does not exist.', [
                'operation_job_id' => $job->id,
                'message' => $message,
            ]);

            return null;
        }

        try {
            $payload = [
                'uuid' => (string) Str::uuid(),
                'operation_job_id' => $job->id,
                'operation_job_item_id' => $item?->id,
                'level' => $level,
                'event' => str($message)->lower()->replace(' ', '_')->replace('.', '')->limit(120, '')->toString(),
                'message' => $message,
                'context' => RedactsSensitiveData::redact($context),
                'created_at' => now(),
            ];

            $payload = $this->filterForTable('operation_job_logs', $payload);

            if (! array_key_exists('operation_job_id', $payload) || blank($payload['operation_job_id'])) {
                Log::warning('Operation job log payload missing operation_job_id.', [
                    'payload' => $payload,
                ]);

                return null;
            }

            if (! array_key_exists('message', $payload) || blank($payload['message'])) {
                $payload['message'] = 'Operation log entry.';
            }

            if (array_key_exists('event', $payload) && blank($payload['event'])) {
                $payload['event'] = 'operation_log_entry';
            }

            if (! array_key_exists('event', $payload) && in_array('event', Schema::getColumnListing('operation_job_logs'), true)) {
                $payload['event'] = 'operation_log_entry';
            }

            if (array_key_exists('uuid', $payload) && blank($payload['uuid'])) {
                $payload['uuid'] = (string) Str::uuid();
            }

            if (array_key_exists('created_at', $payload) && blank($payload['created_at'])) {
                $payload['created_at'] = now();
            }

            $log = new OperationJobLog();
            $log->forceFill($payload);
            $log->save();

            return $log;
        } catch (Throwable $exception) {
            Log::error('Failed to create operation job log.', [
                'operation_job_id' => $job->id,
                'operation_job_item_id' => $item?->id,
                'level' => $level,
                'message' => $message,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeOperationJobData(array $data): array
    {
        $columns = Schema::hasTable('operation_jobs')
            ? Schema::getColumnListing('operation_jobs')
            : [];

        if (isset($data['type']) && in_array('operation_type', $columns, true)) {
            $data['operation_type'] = $data['type'];
        }

        if (isset($data['operation_type']) && in_array('type', $columns, true)) {
            $data['type'] = $data['operation_type'];
        }

        if (isset($data['name']) && in_array('title', $columns, true)) {
            $data['title'] = $data['name'];
        }

        if (isset($data['title']) && in_array('name', $columns, true)) {
            $data['name'] = $data['title'];
        }

        if (isset($data['action']) && in_array('operation_action', $columns, true)) {
            $data['operation_action'] = $data['action'];
        }

        if (isset($data['operation_action']) && in_array('action', $columns, true)) {
            $data['action'] = $data['operation_action'];
        }

        return $data;
    }

    private function normalizeOperationJobItemData(array $data): array
    {
        $columns = Schema::hasTable('operation_job_items')
            ? Schema::getColumnListing('operation_job_items')
            : [];

        if (isset($data['target_identifier']) && in_array('target_key', $columns, true)) {
            $data['target_key'] = $data['target_identifier'];
        }

        if (isset($data['target_key']) && in_array('target_identifier', $columns, true)) {
            $data['target_identifier'] = $data['target_key'];
        }

        return $data;
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
