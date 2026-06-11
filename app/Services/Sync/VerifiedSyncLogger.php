<?php

namespace App\Services\Sync;

use App\Models\Operations\CommandExecution;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class VerifiedSyncLogger
{
    public function verifyAndLog(array $options = []): array
    {
        $connectionId = (int) ($options['connection_id'] ?? 2);
        $source = (string) ($options['source'] ?? 'unknown_sync_source');
        $operation = (string) ($options['operation'] ?? 'ldap_verified_sync');
        $commandExecutionId = $options['command_execution_id'] ?? null;
        $operationJobId = $options['operation_job_id'] ?? null;
        $reason = (string) ($options['reason'] ?? 'post_sync_verification');

        $arguments = [
            '--connection' => $connectionId,
            '--bind-dn' => $options['bind_dn'] ?? 'cn=admin,dc=petra,dc=ac,dc=id',
            '--bind-password' => $options['bind_password'] ?? env('LDAP_VERIFY_BIND_PASSWORD') ?: env('LDAP_ADMIN_PASSWORD') ?: 'SeongJinWoo999!',
            '--json' => true,
        ];

        try {
            $exitCode = Artisan::call('iam:verify-ldap-sync-state', $arguments);
            $output = trim(Artisan::output());
            $decoded = json_decode($output, true);

            $payload = is_array($decoded) ? $decoded : [
                'raw_output' => $output,
                'json_decode_failed' => true,
            ];

            $finalStatus = (string) ($payload['final_status'] ?? 'unknown');

            $context = [
                'operation' => $operation,
                'source' => $source,
                'reason' => $reason,
                'ldap_connection_id' => $connectionId,
                'final_status' => $finalStatus,
                'status_semantics' => $finalStatus === 'success'
                    ? 'success'
                    : 'success_with_warnings',
                'operation_job_id' => $operationJobId,
                'verify_exit_code' => $exitCode,
            ];

            if ($exitCode === 0) {
                if ($commandExecutionId) {
                    SafeCommandExecutionLogger::markSuccess(
                        (int) $commandExecutionId,
                        $payload,
                        $context,
                    );
                } else {
                    $execution = SafeCommandExecutionLogger::createQueued(
                        'ldap_sync_state_verify',
                        'Post-sync verification: '.$source,
                        $context,
                    );

                    SafeCommandExecutionLogger::markSuccess(
                        SafeCommandExecutionLogger::id($execution),
                        $payload,
                        $context,
                    );
                }

                $this->writeOperationJobLog($operationJobId, 'info', 'Post-sync verification completed: '.$finalStatus, $context + [
                    'summary' => $payload,
                ]);

                $this->writeAuditLog($operation, 'success', $source, $context, $payload);

                return [
                    'ok' => true,
                    'final_status' => $finalStatus,
                    'context' => $context,
                    'summary' => $payload,
                ];
            }

            if ($commandExecutionId) {
                SafeCommandExecutionLogger::markFailed(
                    (int) $commandExecutionId,
                    'Post-sync verification failed.',
                    $payload,
                    $context,
                );
            }

            $this->writeOperationJobLog($operationJobId, 'error', 'Post-sync verification failed.', $context + [
                'summary' => $payload,
            ]);

            $this->writeAuditLog($operation, 'failed', $source, $context, $payload);

            return [
                'ok' => false,
                'final_status' => $finalStatus,
                'context' => $context,
                'summary' => $payload,
            ];
        } catch (Throwable $e) {
            $context = [
                'operation' => $operation,
                'source' => $source,
                'reason' => $reason,
                'ldap_connection_id' => $connectionId,
                'operation_job_id' => $operationJobId,
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];

            if ($commandExecutionId) {
                SafeCommandExecutionLogger::markFailed(
                    (int) $commandExecutionId,
                    $e->getMessage(),
                    $context,
                    $context,
                );
            }

            $this->writeOperationJobLog($operationJobId, 'error', $e->getMessage(), $context);
            $this->writeAuditLog($operation, 'failed', $source, $context, [
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'final_status' => 'failed',
                'error' => $e->getMessage(),
                'context' => $context,
            ];
        }
    }

    private function writeOperationJobLog($operationJobId, string $level, string $message, array $context = []): void
    {
        if (! $operationJobId || ! Schema::hasTable('operation_job_logs')) {
            return;
        }

        try {
            $columns = Schema::getColumnListing('operation_job_logs');

            $data = [];

            if (in_array('uuid', $columns, true)) {
                $data['uuid'] = (string) Str::uuid();
            }

            if (in_array('operation_job_id', $columns, true)) {
                $data['operation_job_id'] = $operationJobId;
            }

            if (in_array('operation_job_item_id', $columns, true)) {
                $data['operation_job_item_id'] = null;
            }

            if (in_array('level', $columns, true)) {
                $data['level'] = $level;
            }

            if (in_array('event', $columns, true)) {
                $data['event'] = (string) ($context['operation'] ?? $context['event'] ?? 'post_sync_verification');
            }

            if (in_array('message', $columns, true)) {
                $data['message'] = $message;
            }

            if (in_array('context', $columns, true)) {
                $data['context'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            if (in_array('command', $columns, true)) {
                $data['command'] = $context['command'] ?? 'iam:verify-ldap-sync-state';
            }

            if (in_array('stdout', $columns, true)) {
                $data['stdout'] = isset($context['summary'])
                    ? json_encode($context['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    : null;
            }

            if (in_array('stderr', $columns, true)) {
                $data['stderr'] = $level === 'error' ? $message : null;
            }

            if (in_array('exit_code', $columns, true)) {
                $data['exit_code'] = $level === 'error' ? 1 : 0;
            }

            if (in_array('created_at', $columns, true)) {
                $data['created_at'] = now();
            }

            if (in_array('updated_at', $columns, true)) {
                $data['updated_at'] = now();
            }

            if ($data !== []) {
                DB::table('operation_job_logs')->insert($data);
            }
        } catch (Throwable $e) {
            Log::warning('VerifiedSyncLogger operation job log write failed', [
                'message' => $e->getMessage(),
                'operation_job_id' => $operationJobId,
                'level' => $level,
            ]);
        }
    }

    private function writeAuditLog(string $action, string $status, string $source, array $context = [], array $payload = []): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        try {
            $columns = Schema::getColumnListing('audit_logs');

            $data = [];

            $map = [
                'uuid' => (string) Str::uuid(),

                'module' => 'sync',
                'action' => $action,
                'status' => $status,

                'target_type' => 'ldap_sync',
                'target_key' => $source,
                'target_dn' => $context['target_dn'] ?? null,

                'error_message' => $status === 'failed'
                    ? ($payload['error'] ?? 'Sync verification failed')
                    : null,

                'request_payload' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'after_value' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

                'command' => $context['command'] ?? null,
                'stdout' => isset($payload['final_status'])
                    ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    : null,
                'stderr' => $status === 'failed'
                    ? ($payload['error'] ?? null)
                    : null,
                'exit_code' => $status === 'failed' ? 1 : 0,

                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($map as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $data[$column] = $value;
                }
            }

            if ($data !== []) {
                DB::table('audit_logs')->insert($data);
            }
        } catch (Throwable $e) {
            Log::warning('VerifiedSyncLogger audit log write failed', [
                'message' => $e->getMessage(),
                'action' => $action,
                'status' => $status,
                'source' => $source,
            ]);
        }
    }
}
