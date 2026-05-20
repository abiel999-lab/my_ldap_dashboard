<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\SavedScript;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSearchScriptExecutor
{
    public function execute(SavedScript $script): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $validation = $this->validateExecutable($script);

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.script_engine',
            'command_type' => $script->script_type,
            'status' => $validation['ok'] ? 'running' : 'blocked',
            'command' => $this->redactString($script->script_body),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'script_id' => $script->id,
                'script_name' => $script->name,
                'script_type' => $script->script_type,
                'safe_mode_required' => $script->safe_mode_required,
                'preview_only' => $script->preview_only,
                'destructive' => $script->destructive,
                'validation' => $validation,
                'runner' => 'ldapsearch_safe_executor',
            ]),
            'safe_mode' => true,
            'preview_mode' => false,
            'destructive' => false,
            'started_at' => now(),
        ]);

        if (! $validation['ok']) {
            $execution->forceFill([
                'status' => 'blocked',
                'stderr' => $validation['message'],
                'exit_code' => 126,
                'error_message' => $validation['message'],
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution, $script, 'execute_ldapsearch_script');

            return $execution;
        }

        try {
            $command = $this->buildCommand($script);
            $process = new Process($command, base_path());
            $process->setTimeout(60);
            $process->run();

            $execution->forceFill([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'stdout' => $this->redactString($process->getOutput()),
                'stderr' => $this->redactString($process->getErrorOutput()),
                'exit_code' => $process->getExitCode(),
                'error_message' => $process->isSuccessful() ? null : 'ldapsearch exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution, $script, 'execute_ldapsearch_script');

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

            $this->audit($execution, $script, 'execute_ldapsearch_script');

            return $execution;
        }
    }

    public function validateExecutable(SavedScript $script): array
    {
        if ($script->script_type !== 'ldapsearch') {
            return [
                'ok' => false,
                'message' => 'Only ldapsearch read-only scripts can be executed at this stage.',
            ];
        }

        if ($script->destructive) {
            return [
                'ok' => false,
                'message' => 'Destructive scripts cannot be executed by LDAP search safe executor.',
            ];
        }

        $body = trim((string) $script->script_body);

        if ($body === '') {
            return [
                'ok' => false,
                'message' => 'Script body is empty.',
            ];
        }

        if (! str_starts_with($body, 'ldapsearch ')) {
            return [
                'ok' => false,
                'message' => 'Only commands starting with ldapsearch are allowed.',
            ];
        }

        $blocked = [
            'ldapmodify',
            'ldapadd',
            'ldapdelete',
            'rm -rf',
            'sudo',
            'chmod 777',
            'chown',
            'mkfs',
        ];

        foreach ($blocked as $needle) {
            if (str_contains(strtolower($body), strtolower($needle))) {
                return [
                    'ok' => false,
                    'message' => 'Script contains blocked command pattern: '.$needle,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'ldapsearch script is allowed for safe read-only execution.',
        ];
    }

    private function buildCommand(SavedScript $script): array
    {
        $connection = LdapConnection::query()
            ->where('is_default', true)
            ->first();

        if (! $connection) {
            throw new \RuntimeException('No default LDAP connection found.');
        }

        $baseDn = $connection->base_dn;
        $host = $connection->host;
        $port = $connection->port;
        $bindDn = $connection->bind_dn;
        $bindPassword = $connection->bind_password;

        if (blank($bindDn) || blank($bindPassword)) {
            throw new \RuntimeException('Default LDAP connection does not have bind DN/password configured.');
        }

        return [
            'ldapsearch',
            '-x',
            '-H',
            'ldap://'.$host.':'.$port,
            '-D',
            $bindDn,
            '-w',
            $bindPassword,
            '-b',
            $baseDn,
            '(objectClass=*)',
            'dn',
        ];
    }

    private function audit(CommandExecution $execution, SavedScript $script, string $action): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.script_engine',
            'action' => $action,
            'status' => $execution->status === 'success' ? 'success' : 'failed',
            'target_type' => SavedScript::class,
            'target_key' => (string) $script->id,
            'request_payload' => [
                'script_id' => $script->id,
                'script_name' => $script->name,
                'script_type' => $script->script_type,
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
            '/(-w\s+)([^\s]+)/i',
            '/(bindpw:\s*)([^\s]+)/i',
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
