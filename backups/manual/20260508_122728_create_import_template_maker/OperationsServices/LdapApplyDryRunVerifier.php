<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapApplyDryRunVerifier
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
            'command_type' => 'ldapmodify_dry_run',
            'status' => $validation['ok'] ? 'running' : 'blocked',
            'command' => $this->redactString($this->displayCommand($plan)),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'approval_status' => $plan->approval_status,
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'ldap_will_change' => false,
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

            $this->audit($plan, $execution, 'failed');

            return $execution;
        }

        try {
            $command = $this->buildCommand($plan);

            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            $execution->forceFill([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'error_message' => $process->isSuccessful() ? null : 'ldapmodify dry-run verification exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $plan->forceFill([
                'status' => $process->isSuccessful() ? 'dry_run_verified' : 'dry_run_failed',
                'dry_run_verified_at' => $process->isSuccessful() ? now() : null,
                'dry_run_verified_by' => $process->isSuccessful() ? Auth::id() : null,
                'dry_run_command_execution_id' => $execution->id,
                'dry_run_output_summary' => $this->summarizeOutput($stdout, $stderr),
                'dry_run_error_message' => $process->isSuccessful() ? null : ($stderr ?: $stdout ?: 'Dry run failed.'),
                'message' => $process->isSuccessful()
                    ? 'LDAP apply dry-run verified successfully. LDAP data has not been changed.'
                    : 'LDAP apply dry-run failed. LDAP data has not been changed.',
            ])->save();

            $this->audit($plan, $execution, $process->isSuccessful() ? 'success' : 'failed');

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
                'status' => 'dry_run_failed',
                'dry_run_command_execution_id' => $execution->id,
                'dry_run_error_message' => $exception->getMessage(),
                'message' => 'LDAP apply dry-run failed. LDAP data has not been changed.',
            ])->save();

            $this->audit($plan, $execution, 'failed');

            return $execution;
        }
    }

    private function validatePlan(ImportApplyPlan $plan): array
    {
        $plan->refresh();

        if (! $plan->canVerifyDryRun()) {
            return [
                'ok' => false,
                'message' => 'Plan cannot be dry-run verified. It must be approved, successful, safe, dry-run, non-destructive, and have an LDIF file.',
            ];
        }

        $connection = $plan->ldapConnection;

        if (! $connection && $plan->importBatch?->ldapConnection) {
            $connection = $plan->importBatch->ldapConnection;
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No LDAP connection available for dry-run verification.',
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
            'message' => 'Plan is safe for ldapmodify dry-run verification.',
        ];
    }

    private function buildCommand(ImportApplyPlan $plan): array
    {
        $connection = $plan->ldapConnection;

        if (! $connection && $plan->importBatch?->ldapConnection) {
            $connection = $plan->importBatch->ldapConnection;
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        if (! $connection) {
            throw new \RuntimeException('No LDAP connection available.');
        }

        return [
            'ldapmodify',
            '-n',
            '-v',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-f',
            $plan->outputAbsolutePath(),
        ];
    }

    private function displayCommand(ImportApplyPlan $plan): string
    {
        $connection = $plan->ldapConnection;

        if (! $connection && $plan->importBatch?->ldapConnection) {
            $connection = $plan->importBatch->ldapConnection;
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        return 'ldapadd -n -v -x'
            .' -H ldap://'.($connection?->host ?? 'default').':'.($connection?->port ?? '389')
            .' -D '.($connection?->bind_dn ?? 'default')
            .' -w [REDACTED]'
            .' -f '.($plan->output_path ?? 'N/A');
    }

    private function audit(ImportApplyPlan $plan, CommandExecution $execution, string $status): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.import',
            'action' => 'verify_import_apply_ldapadd_dry_run',
            'status' => $status === 'success' ? 'success' : 'failed',
            'target_type' => ImportApplyPlan::class,
            'target_key' => (string) $plan->id,
            'target_dn' => $plan->importBatch?->base_dn,
            'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
            'operation_job_id' => $plan->operation_job_id,
            'request_payload' => [
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'approval_status' => $plan->approval_status,
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'ldap_was_changed' => false,
            ],
            'command' => $execution->command,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'exit_code' => $execution->exit_code,
            'error_message' => $execution->error_message,
            'duration_ms' => $execution->duration_ms,
        ]);
    }

    private function summarizeOutput(string $stdout, string $stderr): string
    {
        $stdoutLines = $stdout === '' ? 0 : substr_count($stdout, "\n") + 1;
        $stderrLines = $stderr === '' ? 0 : substr_count($stderr, "\n") + 1;

        return 'ldapmodify dry-run completed. stdout lines: '.$stdoutLines.', stderr lines: '.$stderrLines.'. LDAP data was not changed.';
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

    private function containsSupportedChangeType(string $ldifContent): bool
    {
        $normalized = strtolower($ldifContent);

        return str_contains($normalized, 'changetype: add')
            || str_contains($normalized, 'changetype: modify')
            || str_contains($normalized, 'changetype: delete');
    }

}
