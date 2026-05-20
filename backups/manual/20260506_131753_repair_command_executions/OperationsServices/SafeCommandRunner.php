<?php

namespace App\Services\Operations;

use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Request;
use Throwable;

class SafeCommandRunner
{
    private const ALLOWED_COMMANDS = [
        'php artisan --version',
        'php artisan about',
        'php artisan queue:failed',
        'php artisan queue:monitor ldap,default',
        'php artisan route:list',
    ];

    public function allowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

    public function run(string $command, string $commandType = 'safe_artisan'): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.command',
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $this->redactString($command),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
                'queue_connection' => config('queue.default'),
                'safe_runner' => true,
            ]),
            'safe_mode' => true,
            'preview_mode' => false,
            'destructive' => false,
            'started_at' => now(),
        ]);

        if (! in_array($command, self::ALLOWED_COMMANDS, true)) {
            $execution->forceFill([
                'status' => 'blocked',
                'stderr' => 'Command is not in the allowed safe command list.',
                'exit_code' => 126,
                'error_message' => 'Blocked unsafe command.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution);

            return $execution;
        }

        try {
            $result = Process::path(base_path())
                ->timeout(60)
                ->run($command);

            $execution->forceFill([
                'status' => $result->successful() ? 'success' : 'failed',
                'stdout' => $this->redactString($result->output()),
                'stderr' => $this->redactString($result->errorOutput()),
                'exit_code' => $result->exitCode(),
                'error_message' => $result->successful() ? null : 'Command exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution);

            return $execution;
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution);

            return $execution;
        }
    }

    private function audit(CommandExecution $execution): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.command',
            'action' => 'run_safe_command',
            'status' => in_array($execution->status, ['success'], true) ? 'success' : 'failed',
            'target_type' => CommandExecution::class,
            'target_key' => (string) $execution->id,
            'request_payload' => [
                'command_type' => $execution->command_type,
                'safe_mode' => $execution->safe_mode,
                'preview_mode' => $execution->preview_mode,
                'destructive' => $execution->destructive,
            ],
            'command' => $execution->command,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'exit_code' => $execution->exit_code,
            'error_message' => $execution->error_message,
            'duration_ms' => $execution->duration_ms,
        ]);
    }

    private function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(password\s*[=:]\s*)([^\s]+)/i',
            '/(bind_password\s*[=:]\s*)([^\s]+)/i',
            '/(client_secret\s*[=:]\s*)([^\s]+)/i',
            '/(token\s*[=:]\s*)([^\s]+)/i',
            '/(Authorization:\s*Bearer\s+)([^\s]+)/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
