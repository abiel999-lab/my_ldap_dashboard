<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationActivityDetailService
{
    public function detailForJob(int $jobId): array
    {
        $job = $this->rowById('operation_jobs', $jobId);

        return [
            'job' => $job,
            'logs' => $this->operationLogsForJob($jobId),
            'items' => $this->operationItemsForJob($jobId),
            'audits' => $this->auditLogsForJob($jobId, $job),
            'commands' => $this->commandExecutionsForJob($jobId, $job),
        ];
    }

    private function rowById(string $table, int $id): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    public function operationLogsForJob(int $jobId): array
    {
        return $this->rowsByJobId('operation_job_logs', $jobId, 100);
    }

    public function operationItemsForJob(int $jobId): array
    {
        return $this->rowsByJobId('operation_job_items', $jobId, 100);
    }

    private function rowsByJobId(string $table, int $jobId, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        $query = DB::table($table);

        if (in_array('operation_job_id', $columns, true)) {
            $query->where('operation_job_id', $jobId);
        } elseif (in_array('job_id', $columns, true)) {
            $query->where('job_id', $jobId);
        } else {
            return [];
        }

        if (in_array('created_at', $columns, true)) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('id');
        }

        return $query->limit($limit)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function auditLogsForJob(int $jobId, ?array $job = null): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $job ??= $this->rowById('operation_jobs', $jobId);

        $columns = Schema::getColumnListing('audit_logs');
        $query = DB::table('audit_logs');

        $targetId = $job['target_id'] ?? null;
        $targetDn = $job['target_dn'] ?? null;
        $module = $job['module'] ?? null;
        $action = $job['action'] ?? null;
        $operationType = $job['operation_type'] ?? $job['type'] ?? null;

        $query->where(function ($q) use ($columns, $jobId, $targetId, $targetDn, $module, $action, $operationType) {
            if (in_array('target_id', $columns, true)) {
                $q->orWhere('target_id', $jobId);

                if ($targetId !== null) {
                    $q->orWhere('target_id', $targetId);
                }
            }

            if ($targetDn && in_array('target_dn', $columns, true)) {
                $q->orWhere('target_dn', $targetDn);
            }

            if ($module && in_array('module', $columns, true)) {
                $q->orWhere('module', $module);
            }

            if ($action && in_array('action', $columns, true)) {
                $q->orWhere('action', $action);
            }

            if (in_array('metadata', $columns, true)) {
                $q->orWhere('metadata', 'like', '%"operation_job_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"job_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"target_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"target_id":"'.$jobId.'"%');

                if ($targetId !== null) {
                    $q->orWhere('metadata', 'like', '%"target_id":'.$targetId.'%')
                        ->orWhere('metadata', 'like', '%"target_id":"'.$targetId.'"%');
                }

                if ($targetDn) {
                    $q->orWhere('metadata', 'like', '%'.$targetDn.'%');
                }

                if ($module) {
                    $q->orWhere('metadata', 'like', '%'.$module.'%');
                }

                if ($operationType) {
                    $q->orWhere('metadata', 'like', '%'.$operationType.'%');
                }
            }
        });

        if (in_array('created_at', $columns, true)) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('id');
        }

        return $query->limit(50)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function commandExecutionsForJob(int $jobId, ?array $job = null): array
    {
        if (! Schema::hasTable('command_executions')) {
            return [];
        }

        $job ??= $this->rowById('operation_jobs', $jobId);

        $columns = Schema::getColumnListing('command_executions');
        $query = DB::table('command_executions');

        $targetId = $job['target_id'] ?? null;
        $targetDn = $job['target_dn'] ?? null;
        $module = $job['module'] ?? null;
        $action = $job['action'] ?? null;
        $operationType = $job['operation_type'] ?? $job['type'] ?? null;

        $query->where(function ($q) use ($columns, $jobId, $targetId, $targetDn, $module, $action, $operationType) {
            if (in_array('target_id', $columns, true)) {
                $q->orWhere('target_id', $jobId);

                if ($targetId !== null) {
                    $q->orWhere('target_id', $targetId);
                }
            }

            if ($targetDn && in_array('target_dn', $columns, true)) {
                $q->orWhere('target_dn', $targetDn);
            }

            if ($module && in_array('module', $columns, true)) {
                $q->orWhere('module', $module);
            }

            if ($action && in_array('action', $columns, true)) {
                $q->orWhere('action', $action);
            }

            if ($operationType && in_array('command_type', $columns, true)) {
                $q->orWhere('command_type', $operationType);
            }

            if (in_array('metadata', $columns, true)) {
                $q->orWhere('metadata', 'like', '%"operation_job_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"job_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"target_id":'.$jobId.'%')
                    ->orWhere('metadata', 'like', '%"target_id":"'.$jobId.'"%');

                if ($targetId !== null) {
                    $q->orWhere('metadata', 'like', '%"target_id":'.$targetId.'%')
                        ->orWhere('metadata', 'like', '%"target_id":"'.$targetId.'"%');
                }

                if ($targetDn) {
                    $q->orWhere('metadata', 'like', '%'.$targetDn.'%');
                }

                if ($module) {
                    $q->orWhere('metadata', 'like', '%'.$module.'%');
                }

                if ($operationType) {
                    $q->orWhere('metadata', 'like', '%'.$operationType.'%');
                }
            }
        });

        if (in_array('created_at', $columns, true)) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('id');
        }

        return $query->limit(50)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function pretty(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return 'N/A';
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function countSummary(int $jobId): array
    {
        $detail = $this->detailForJob($jobId);

        return [
            'items' => count($detail['items']),
            'logs' => count($detail['logs']),
            'audits' => count($detail['audits']),
            'commands' => count($detail['commands']),
        ];
    }
}
