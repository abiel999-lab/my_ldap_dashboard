<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class ImportPostApplyVerifier
{
    public function verify(ImportApplyPlan $plan): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $validation = $this->validatePlan($plan);

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.import',
            'command_type' => 'post_apply_ldapsearch_verify',
            'status' => $validation['ok'] ? 'running' : 'blocked',
            'command' => $this->redactString($this->displayCommand($plan)),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'ldap_will_change' => false,
                'verification_only' => true,
                'validation' => $validation,
            ]),
            'safe_mode' => true,
            'preview_mode' => true,
            'destructive' => false,
            'operation_job_id' => $plan->operation_job_id,
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

            $this->audit($plan, $execution, 'failed', 0, 0);

            return $execution;
        }

        try {
            $dns = $this->extractDnsFromLdif($plan);

            if ($dns === []) {
                throw new \RuntimeException('No DN entries found inside LDIF apply plan.');
            }

            $connection = $this->resolveConnection($plan);

            if (! $connection) {
                throw new \RuntimeException('No LDAP connection available.');
            }

            $stdoutAll = [];
            $stderrAll = [];
            $verifiedCount = 0;
            $missingCount = 0;
            $exitCode = 0;

            foreach ($dns as $dn) {
                $process = new Process([
                    'ldapsearch',
                    '-LLL',
                    '-x',
                    '-H',
                    'ldap://'.$connection->host.':'.$connection->port,
                    '-D',
                    $connection->bind_dn,
                    '-w',
                    $connection->bind_password,
                    '-b',
                    $dn,
                    '-s',
                    'base',
                    '(objectClass=*)',
                    'dn',
                    'objectClass',
                    'uid',
                    'cn',
                    'sn',
                    'mail',
                ], base_path());

                $process->setTimeout(60);
                $process->run();

                $stdout = $process->getOutput();
                $stderr = $process->getErrorOutput();

                $stdoutAll[] = "===== VERIFY DN: {$dn} =====\n".$stdout;
                $stderrAll[] = "===== VERIFY DN: {$dn} =====\n".$stderr;

                if ($process->isSuccessful() && str_contains($stdout, 'dn: '.$dn)) {
                    $verifiedCount++;
                } else {
                    $missingCount++;
                    $exitCode = $process->getExitCode() ?: 1;
                }
            }

            $stdoutText = $this->redactString(implode("\n", $stdoutAll));
            $stderrText = $this->redactString(implode("\n", $stderrAll));
            $success = $missingCount === 0;

            $execution->forceFill([
                'status' => $success ? 'success' : 'failed',
                'stdout' => $stdoutText,
                'stderr' => $stderrText,
                'exit_code' => $success ? 0 : $exitCode,
                'error_message' => $success ? null : 'Some applied LDAP entries could not be verified.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $plan->forceFill([
                'status' => $success ? 'verified_applied' : 'post_apply_verification_failed',
                'post_apply_verified_at' => $success ? now() : null,
                'post_apply_verified_by' => $success ? Auth::id() : null,
                'post_apply_command_execution_id' => $execution->id,
                'post_apply_verified_count' => $verifiedCount,
                'post_apply_missing_count' => $missingCount,
                'post_apply_output_summary' => 'Post-apply verification completed. Verified: '.$verifiedCount.', Missing: '.$missingCount.'.',
                'post_apply_error_message' => $success ? null : 'Some entries were not found after apply.',
                'message' => $success
                    ? 'Post-apply verification succeeded. Applied LDAP entries were found.'
                    : 'Post-apply verification failed. Some entries were not found.',
            ])->save();

            $this->audit($plan, $execution, $success ? 'success' : 'failed', $verifiedCount, $missingCount);

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

            $plan->forceFill([
                'status' => 'post_apply_verification_failed',
                'post_apply_command_execution_id' => $execution->id,
                'post_apply_error_message' => $exception->getMessage(),
                'message' => 'Post-apply verification failed.',
            ])->save();

            $this->audit($plan, $execution, 'failed', 0, 0);

            return $execution;
        }
    }

    private function validatePlan(ImportApplyPlan $plan): array
    {
        $plan->refresh();

        if (! $plan->canVerifyPostApply()) {
            return [
                'ok' => false,
                'message' => 'Plan cannot be post-apply verified. It must be applied and have an LDIF file.',
            ];
        }

        $connection = $this->resolveConnection($plan);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No LDAP connection available for post-apply verification.',
            ];
        }

        if (blank($connection->bind_dn) || blank($connection->bind_password)) {
            return [
                'ok' => false,
                'message' => 'LDAP connection bind DN/password is missing.',
            ];
        }

        if (! $plan->hasOutputFile()) {
            return [
                'ok' => false,
                'message' => 'Generated LDIF apply plan file is missing.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Plan can be post-apply verified.',
        ];
    }

    private function extractDnsFromLdif(ImportApplyPlan $plan): array
    {
        $path = $plan->outputAbsolutePath();

        if (! $path || ! File::exists($path)) {
            return [];
        }

        $content = File::get($path);
        $dns = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'dn: ')) {
                $dns[] = trim(substr($line, 4));
            }
        }

        return collect($dns)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function displayCommand(ImportApplyPlan $plan): string
    {
        $connection = $this->resolveConnection($plan);

        return 'ldapsearch -LLL -x'
            .' -H ldap://'.($connection?->host ?? 'default').':'.($connection?->port ?? '389')
            .' -D '.($connection?->bind_dn ?? 'default')
            .' -w [REDACTED]'
            .' -b [EACH_DN_FROM_LDIF]'
            .' -s base "(objectClass=*)" dn objectClass uid cn sn mail';
    }

    private function resolveConnection(ImportApplyPlan $plan): ?LdapConnection
    {
        $connection = $plan->ldapConnection;

        if (! $connection && $plan->importBatch?->ldapConnection) {
            $connection = $plan->importBatch->ldapConnection;
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        return $connection;
    }

    private function audit(ImportApplyPlan $plan, CommandExecution $execution, string $status, int $verifiedCount, int $missingCount): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.import',
            'action' => 'post_apply_verify_ldap_entries',
            'status' => $status === 'success' ? 'success' : 'failed',
            'target_type' => ImportApplyPlan::class,
            'target_key' => (string) $plan->id,
            'target_dn' => $plan->importBatch?->base_dn,
            'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
            'operation_job_id' => $plan->operation_job_id,
            'request_payload' => [
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'ldap_was_changed' => false,
                'verification_only' => true,
            ],
            'after_value' => [
                'verified_count' => $verifiedCount,
                'missing_count' => $missingCount,
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
