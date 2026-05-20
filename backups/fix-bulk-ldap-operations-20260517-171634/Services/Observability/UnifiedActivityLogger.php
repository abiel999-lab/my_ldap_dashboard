<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UnifiedActivityLogger
{
    public function success(
        string $module,
        string $action,
        string $message,
        array $context = []
    ): array {
        return $this->write('success', 'info', $module, $action, $message, $context);
    }

    public function failed(
        string $module,
        string $action,
        string $message,
        array $context = []
    ): array {
        return $this->write('failed', 'error', $module, $action, $message, $context);
    }

    public function warning(
        string $module,
        string $action,
        string $message,
        array $context = []
    ): array {
        return $this->write('warning', 'warning', $module, $action, $message, $context);
    }

    public function info(
        string $module,
        string $action,
        string $message,
        array $context = []
    ): array {
        return $this->write('info', 'info', $module, $action, $message, $context);
    }

    private function write(
        string $status,
        string $level,
        string $module,
        string $action,
        string $message,
        array $context
    ): array {
        $result = [
            'operation_job_id' => null,
            'operation_job_log_id' => null,
            'audit_log_id' => null,
            'command_execution_id' => null,
            'errors' => [],
        ];

        $context = $this->normalizeContext($context);

        try {
            $result['operation_job_id'] = $this->writeOperationJob(
                status: $status,
                module: $module,
                action: $action,
                message: $message,
                context: $context,
            );
        } catch (Throwable $exception) {
            $result['errors'][] = 'operation_jobs: '.$exception->getMessage();
        }

        try {
            $result['operation_job_log_id'] = $this->writeOperationJobLog(
                jobId: $result['operation_job_id'],
                level: $level,
                message: $message,
                context: $context,
            );
        } catch (Throwable $exception) {
            $result['errors'][] = 'operation_job_logs: '.$exception->getMessage();
        }

        try {
            $result['audit_log_id'] = $this->writeAuditLog(
                status: $status,
                module: $module,
                action: $action,
                message: $message,
                context: $context,
            );
        } catch (Throwable $exception) {
            $result['errors'][] = 'audit_logs: '.$exception->getMessage();
        }

        /*
         * Command Executions is noisy, so only write there if explicitly requested.
         * Example context:
         * ['write_command_execution' => true]
         */
        if (($context['write_command_execution'] ?? false) === true) {
            try {
                $result['command_execution_id'] = $this->writeCommandExecution(
                    status: $status,
                    module: $module,
                    action: $action,
                    message: $message,
                    context: $context,
                );
            } catch (Throwable $exception) {
                $result['errors'][] = 'command_executions: '.$exception->getMessage();
            }
        }

        return $result;
    }

    private function writeOperationJob(
        string $status,
        string $module,
        string $action,
        string $message,
        array $context
    ): ?int {
        if (! Schema::hasTable('operation_jobs')) {
            return null;
        }

        $columns = Schema::getColumnListing('operation_jobs');
        $now = now();

        $operationType = $context['operation_type']
            ?? $context['type']
            ?? $module;

        $jobName = $context['name'] ?? $this->humanTitle($module, $action, $context);

        $data = [];

        $this->put($data, $columns, 'uuid', (string) \Illuminate\Support\Str::uuid());

        /*
         * Existing table uses operation_type as required column.
         * Keep aliases too so the logger works across old/new schemas.
         */
        $this->put($data, $columns, 'operation_type', $operationType);
        $this->put($data, $columns, 'title', $jobName);
        $this->put($data, $columns, 'name', $jobName);
        $this->put($data, $columns, 'type', $operationType);
        $this->put($data, $columns, 'module', $module);
        $this->put($data, $columns, 'action', $action);
        $this->put($data, $columns, 'status', $status);
        $this->put($data, $columns, 'queue', $context['queue'] ?? 'default');
        $this->put($data, $columns, 'source', $context['source'] ?? 'filament');
        $this->put($data, $columns, 'message', $message);

        /*
         * Count aliases. Your table appears to have several counters.
         */
        $total = $context['total'] ?? $context['total_rows'] ?? $context['total_items'] ?? 1;
        $success = $context['success'] ?? $context['success_rows'] ?? $context['success_items'] ?? ($status === 'success' ? 1 : 0);
        $failed = $context['failed'] ?? $context['failed_rows'] ?? $context['failed_items'] ?? ($status === 'failed' ? 1 : 0);
        $skipped = $context['skipped'] ?? $context['skipped_rows'] ?? $context['skipped_items'] ?? 0;

        $this->put($data, $columns, 'total_items', $total);
        $this->put($data, $columns, 'success_items', $success);
        $this->put($data, $columns, 'failed_items', $failed);
        $this->put($data, $columns, 'skipped_items', $skipped);

        $this->put($data, $columns, 'total_count', $total);
        $this->put($data, $columns, 'success_count', $success);
        $this->put($data, $columns, 'failed_count', $failed);
        $this->put($data, $columns, 'skipped_count', $skipped);

        $this->put($data, $columns, 'processed_items', $success + $failed + $skipped);
        $this->put($data, $columns, 'processed_count', $success + $failed + $skipped);

        $this->put($data, $columns, 'target_type', $context['target_type'] ?? null);
        $this->put($data, $columns, 'target_id', $context['target_id'] ?? null);
        $this->put($data, $columns, 'target_dn', $context['target_dn'] ?? null);
        $this->put($data, $columns, 'ldap_connection_id', $context['ldap_connection_id'] ?? null);

        $this->putJson($data, $columns, 'metadata', $context);
        $this->putJson($data, $columns, 'context', $context);

        $this->put($data, $columns, 'started_at', $context['started_at'] ?? $now);
        $this->put($data, $columns, 'finished_at', $context['finished_at'] ?? $now);
        $this->put($data, $columns, 'created_at', $now);
        $this->put($data, $columns, 'updated_at', $now);

        if ($data === []) {
            return null;
        }

        return (int) DB::table('operation_jobs')->insertGetId($data);
    }

    private function writeOperationJobLog(
        ?int $jobId,
        string $level,
        string $message,
        array $context
    ): ?int {
        if (! Schema::hasTable('operation_job_logs')) {
            return null;
        }

        $columns = Schema::getColumnListing('operation_job_logs');
        $now = now();

        $event = $context['event']
            ?? $context['action']
            ?? $context['operation_event']
            ?? 'activity_logged';

        $data = [];

        $this->put($data, $columns, 'uuid', (string) \Illuminate\Support\Str::uuid());

        /*
         * Existing table requires event.
         */
        $this->put($data, $columns, 'event', $event);

        $this->put($data, $columns, 'operation_job_id', $jobId);
        $this->put($data, $columns, 'job_id', $jobId);
        $this->put($data, $columns, 'operation_job_item_id', $context['operation_job_item_id'] ?? null);
        $this->put($data, $columns, 'item_id', $context['item_id'] ?? null);

        $this->put($data, $columns, 'level', $level);
        $this->put($data, $columns, 'message', $message);
        $this->put($data, $columns, 'status', $context['status'] ?? null);
        $this->put($data, $columns, 'target_type', $context['target_type'] ?? null);
        $this->put($data, $columns, 'target_id', $context['target_id'] ?? null);
        $this->put($data, $columns, 'target_dn', $context['target_dn'] ?? null);

        $this->putJson($data, $columns, 'context', $context);
        $this->putJson($data, $columns, 'metadata', $context);

        $this->put($data, $columns, 'created_at', $now);
        $this->put($data, $columns, 'updated_at', $now);

        if ($data === []) {
            return null;
        }

        return (int) DB::table('operation_job_logs')->insertGetId($data);
    }

    private function writeAuditLog(
        string $status,
        string $module,
        string $action,
        string $message,
        array $context
    ): ?int {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $columns = Schema::getColumnListing('audit_logs');
        $now = now();

        $actor = $this->actor();

        $data = [];

        $this->put($data, $columns, 'uuid', (string) \Illuminate\Support\Str::uuid());
        $this->put($data, $columns, 'module', $module);
        $this->put($data, $columns, 'action', $action);
        $this->put($data, $columns, 'status', $status);
        $this->put($data, $columns, 'message', $message);
        $this->put($data, $columns, 'actor', $actor['email'] ?? $actor['name'] ?? 'System');
        $this->put($data, $columns, 'actor_id', $actor['id'] ?? null);
        $this->put($data, $columns, 'actor_name', $actor['name'] ?? null);
        $this->put($data, $columns, 'actor_email', $actor['email'] ?? null);
        $this->put($data, $columns, 'target_type', $context['target_type'] ?? null);
        $this->put($data, $columns, 'target_id', $context['target_id'] ?? null);
        $this->put($data, $columns, 'target_dn', $context['target_dn'] ?? null);
        $this->put($data, $columns, 'ip_address', $this->safeIp());
        $this->put($data, $columns, 'user_agent', substr((string) Request::userAgent(), 0, 1000));
        $this->putJson($data, $columns, 'metadata', $context);
        $this->putJson($data, $columns, 'context', $context);
        $this->put($data, $columns, 'created_at', $now);
        $this->put($data, $columns, 'updated_at', $now);

        if ($data === []) {
            return null;
        }

        return (int) DB::table('audit_logs')->insertGetId($data);
    }

    private function writeCommandExecution(
        string $status,
        string $module,
        string $action,
        string $message,
        array $context
    ): ?int {
        if (! Schema::hasTable('command_executions')) {
            return null;
        }

        $columns = Schema::getColumnListing('command_executions');
        $now = now();

        $command = $context['command'] ?? $action;
        $commandType = $context['command_type']
            ?? $context['type']
            ?? $module
            ?? 'operations.command';

        $safe = (bool) ($context['safe'] ?? true);
        $danger = (bool) ($context['danger'] ?? false);
        $exitCode = $status === 'success' ? 0 : 1;

        $data = [];

        $this->put($data, $columns, 'uuid', (string) \Illuminate\Support\Str::uuid());

        /*
         * Existing table requires command_type.
         */
        $this->put($data, $columns, 'command_type', $commandType);

        $this->put($data, $columns, 'type', $commandType);
        $this->put($data, $columns, 'module', $module);
        $this->put($data, $columns, 'action', $action);
        $this->put($data, $columns, 'command', $command);
        $this->put($data, $columns, 'status', $status === 'success' ? 'success' : 'failed');
        $this->put($data, $columns, 'safe', $safe);
        $this->put($data, $columns, 'is_safe', $safe);
        $this->put($data, $columns, 'danger', $danger);
        $this->put($data, $columns, 'is_dangerous', $danger);
        $this->put($data, $columns, 'exit_code', $exitCode);
        $this->put($data, $columns, 'stdout', $message);
        $this->put($data, $columns, 'stderr', $status === 'success' ? null : $message);
        $this->put($data, $columns, 'duration_ms', $context['duration_ms'] ?? null);
        $this->put($data, $columns, 'target_type', $context['target_type'] ?? null);
        $this->put($data, $columns, 'target_id', $context['target_id'] ?? null);
        $this->put($data, $columns, 'target_dn', $context['target_dn'] ?? null);

        $this->putJson($data, $columns, 'metadata', $context);
        $this->putJson($data, $columns, 'context', $context);

        $this->put($data, $columns, 'created_at', $now);
        $this->put($data, $columns, 'updated_at', $now);

        if ($data === []) {
            return null;
        }

        return (int) DB::table('command_executions')->insertGetId($data);
    }

    private function normalizeContext(array $context): array
    {
        $context['actor'] = $context['actor'] ?? $this->actor();
        $context['ip_address'] = $context['ip_address'] ?? $this->safeIp();

        return $context;
    }

    private function humanTitle(string $module, string $action, array $context): string
    {
        $target = $context['target_label']
            ?? $context['target_dn']
            ?? $context['target_id']
            ?? null;

        $title = str_replace(['.', '_'], ' ', $module).' - '.str_replace('_', ' ', $action);

        if ($target) {
            $title .= ' - '.$target;
        }

        return \Illuminate\Support\Str::title($title);
    }

    private function actor(): array
    {
        try {
            $user = Auth::user();

            if (! $user) {
                return [
                    'id' => null,
                    'name' => 'System',
                    'email' => null,
                ];
            }

            return [
                'id' => $user->id ?? null,
                'name' => $user->name ?? $user->email ?? 'User',
                'email' => $user->email ?? null,
            ];
        } catch (Throwable) {
            return [
                'id' => null,
                'name' => 'System',
                'email' => null,
            ];
        }
    }

    private function safeIp(): ?string
    {
        try {
            return Request::ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function put(array &$data, array $columns, string $column, mixed $value): void
    {
        if (! in_array($column, $columns, true)) {
            return;
        }

        $data[$column] = $value;
    }

    private function putJson(array &$data, array $columns, string $column, mixed $value): void
    {
        if (! in_array($column, $columns, true)) {
            return;
        }

        $data[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
