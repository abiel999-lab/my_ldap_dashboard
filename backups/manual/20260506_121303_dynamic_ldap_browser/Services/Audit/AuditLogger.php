<?php

namespace App\Services\Audit;

use App\Models\Audit\AuditLog;
use App\Models\Directory\LdapConnection;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

class AuditLogger
{
    public function log(array $data): ?AuditLog
    {
        try {
            $user = Auth::user();

            $payload = array_merge([
                'actor_user_id' => $user?->id,
                'actor_name' => $user?->name,
                'actor_email' => $user?->email,
                'actor_ip' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'status' => 'success',
                'created_at' => now(),
            ], $data);

            foreach (['request_payload', 'before_value', 'after_value'] as $jsonField) {
                if (array_key_exists($jsonField, $payload)) {
                    $payload[$jsonField] = RedactsSensitiveData::redact($payload[$jsonField]);
                }
            }

            if (array_key_exists('command', $payload)) {
                $payload['command'] = $this->redactString((string) $payload['command']);
            }

            if (array_key_exists('stdout', $payload)) {
                $payload['stdout'] = $this->redactString((string) $payload['stdout']);
            }

            if (array_key_exists('stderr', $payload)) {
                $payload['stderr'] = $this->redactString((string) $payload['stderr']);
            }

            return AuditLog::query()->create($payload);
        } catch (Throwable) {
            return null;
        }
    }

    public function logModelAction(
        string $module,
        string $action,
        string $status,
        Model $target,
        ?array $before = null,
        ?array $after = null,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): ?AuditLog {
        return $this->log([
            'module' => $module,
            'action' => $action,
            'status' => $status,
            'target_type' => $target::class,
            'target_key' => (string) $target->getKey(),
            'ldap_connection_id' => $target instanceof LdapConnection ? $target->id : null,
            'target_dn' => $target instanceof LdapConnection ? $target->base_dn : null,
            'before_value' => $before,
            'after_value' => $after,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
        ]);
    }

    private function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(password\\s*[=:]\\s*)([^\\s]+)/i',
            '/(bind_password\\s*[=:]\\s*)([^\\s]+)/i',
            '/(client_secret\\s*[=:]\\s*)([^\\s]+)/i',
            '/(token\\s*[=:]\\s*)([^\\s]+)/i',
            '/(Authorization:\\s*Bearer\\s+)([^\\s]+)/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }
}
