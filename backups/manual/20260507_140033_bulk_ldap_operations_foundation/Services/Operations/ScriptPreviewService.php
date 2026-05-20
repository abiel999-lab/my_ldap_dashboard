<?php

namespace App\Services\Operations;

use App\Models\Operations\CommandExecution;
use App\Models\Operations\SavedScript;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ScriptPreviewService
{
    public function preview(SavedScript $script): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $validation = $this->validateScript($script);

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.script_engine',
            'command_type' => $script->script_type,
            'status' => $validation['ok'] ? 'previewed' : 'blocked',
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
            ]),
            'safe_mode' => true,
            'preview_mode' => true,
            'destructive' => (bool) $script->destructive,
            'stdout' => $validation['ok']
                ? "Preview generated successfully.\n\nNo command was executed.\n\n".$this->redactString($script->script_body)
                : null,
            'stderr' => $validation['ok'] ? null : $validation['message'],
            'exit_code' => $validation['ok'] ? 0 : 126,
            'error_message' => $validation['ok'] ? null : $validation['message'],
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        app(AuditLogger::class)->log([
            'module' => 'operations.script_engine',
            'action' => 'preview_script',
            'status' => $validation['ok'] ? 'success' : 'failed',
            'target_type' => SavedScript::class,
            'target_key' => (string) $script->id,
            'request_payload' => [
                'script_type' => $script->script_type,
                'safe_mode_required' => $script->safe_mode_required,
                'preview_only' => $script->preview_only,
                'destructive' => $script->destructive,
            ],
            'command' => $execution->command,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'exit_code' => $execution->exit_code,
            'error_message' => $execution->error_message,
            'duration_ms' => $execution->duration_ms,
        ]);

        return $execution;
    }

    public function validateScript(SavedScript $script): array
    {
        $body = trim((string) $script->script_body);

        if ($body === '') {
            return [
                'ok' => false,
                'message' => 'Script body is empty.',
            ];
        }

        $dangerousPatterns = [
            '/\brm\s+-rf\b/i',
            '/\bsudo\b/i',
            '/\bchmod\s+777\b/i',
            '/\bchown\b/i',
            '/\bdd\s+if=/i',
            '/>\s*\/dev\/sd/i',
            '/\bmkfs\b/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $body)) {
                return [
                    'ok' => false,
                    'message' => 'Script contains blocked dangerous shell pattern.',
                ];
            }
        }

        if ($script->script_type === 'ldapmodify' || $script->script_type === 'ldapadd' || $script->script_type === 'ldapdelete') {
            if (! $script->preview_only) {
                return [
                    'ok' => false,
                    'message' => 'Destructive LDAP script types must remain preview-only at this stage.',
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'Script passed preview validation. No command was executed.',
        ];
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
}
