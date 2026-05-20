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

class LdapRealApplyExecutor
{
    public function apply(ImportApplyPlan $plan, string $confirmation): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $validation = $this->validatePlan($plan, $confirmation);

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.import',
            'command_type' => 'ldapmodify_real_apply',
            'status' => $validation['ok'] ? 'running' : 'blocked',
            'command' => $this->redactString($this->displayCommand($plan)),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'import_apply_plan_id' => $plan->id,
                'import_batch_id' => $plan->import_batch_id,
                'approval_status' => $plan->approval_status,
                'safe_mode_before_apply' => $plan->safe_mode,
                'dry_run_before_apply' => $plan->dry_run,
                'destructive_before_apply' => $plan->destructive,
                'dry_run_verified_at' => $plan->dry_run_verified_at?->toDateTimeString(),
                'ldap_will_change' => true,
                'confirmation' => $confirmation,
                'validation' => $validation,
            ]),
            'safe_mode' => false,
            'preview_mode' => false,
            'destructive' => true,
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

            $this->audit($plan, $execution, 'failed', false);

            return $execution;
        }

        try {
            $this->createPreApplyFileBackup($plan);

            $plan->forceFill([
                'status' => 'apply_running',
                'real_apply_started_at' => now(),
                'real_apply_by' => Auth::id(),
                'real_apply_confirmation' => $confirmation,
                'message' => 'Real LDAP apply is running.',
            ])->save();

            $command = $this->buildCommand($plan);

            $process = new Process($command, base_path());
            $process->setTimeout(180);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            $execution->forceFill([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'error_message' => $process->isSuccessful() ? null : 'ldapmodify real apply exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $plan->forceFill([
                'status' => $process->isSuccessful() ? 'applied' : 'apply_failed',
                'real_apply_finished_at' => now(),
                'real_apply_command_execution_id' => $execution->id,
                'real_apply_output_summary' => $this->summarizeOutput($stdout, $stderr),
                'real_apply_error_message' => $process->isSuccessful() ? null : ($stderr ?: $stdout ?: 'Real apply failed.'),
                'message' => $process->isSuccessful()
                    ? 'Real LDAP apply completed successfully. LDAP data has been changed.'
                    : 'Real LDAP apply failed. Check command execution output.',
                'safe_mode' => false,
                'dry_run' => false,
                'destructive' => true,
            ])->save();

            $this->audit($plan, $execution, $process->isSuccessful() ? 'success' : 'failed', $process->isSuccessful());

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
                'status' => 'apply_failed',
                'real_apply_finished_at' => now(),
                'real_apply_command_execution_id' => $execution->id,
                'real_apply_error_message' => $exception->getMessage(),
                'message' => 'Real LDAP apply failed. Check command execution output.',
            ])->save();

            $this->audit($plan, $execution, 'failed', false);

            return $execution;
        }
    }

    private function validatePlan(ImportApplyPlan $plan, string $confirmation): array
    {
        $plan->refresh();

        if ($confirmation !== 'APPLY LDAP') {
            return [
                'ok' => false,
                'message' => 'Manual confirmation text must exactly be: APPLY LDAP',
            ];
        }

        if (! $plan->canRealApply()) {
            return [
                'ok' => false,
                'message' => 'Plan cannot be applied. It must be approved, dry_run_verified, safe, and not already applied.',
            ];
        }

        $connection = $this->resolveConnection($plan);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No LDAP connection available for real apply.',
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

        $content = File::get($plan->outputAbsolutePath());

        if (! str_contains($content, 'changetype: add')) {
            return [
                'ok' => false,
                'message' => 'LDIF plan does not contain any supported changetype: add, modify, or delete. Refusing real apply.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Plan is allowed for real LDAP apply.',
        ];
    }

    private function buildCommand(ImportApplyPlan $plan): array
    {
        $connection = $this->resolveConnection($plan);

        if (! $connection) {
            throw new \RuntimeException('No LDAP connection available.');
        }

        return [
            'ldapmodify',
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
        $connection = $this->resolveConnection($plan);

        return 'ldapmodify -v -x'
            .' -H ldap://'.($connection?->host ?? 'default').':'.($connection?->port ?? '389')
            .' -D '.($connection?->bind_dn ?? 'default')
            .' -w [REDACTED]'
            .' -f '.($plan->output_path ?? 'N/A');
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

    private function createPreApplyFileBackup(ImportApplyPlan $plan): void
    {
        if (! $plan->hasOutputFile()) {
            return;
        }

        $source = $plan->outputAbsolutePath();

        if (! $source || ! File::exists($source)) {
            return;
        }

        $backupDir = storage_path('app/private/imports/apply-backups');
        File::ensureDirectoryExists($backupDir);

        $backupPath = $backupDir.'/pre_apply_plan_'.$plan->id.'_'.now()->format('Ymd_His').'_'.$plan->outputFilename();

        File::copy($source, $backupPath);
    }

    private function audit(ImportApplyPlan $plan, CommandExecution $execution, string $status, bool $ldapWasChanged): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.import',
            'action' => 'real_import_apply_ldapmodify',
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
                'dry_run_verified_at' => $plan->dry_run_verified_at?->toDateTimeString(),
                'manual_confirmation' => $plan->real_apply_confirmation,
                'ldap_was_changed' => $ldapWasChanged,
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

        return 'ldapmodify real apply completed. stdout lines: '.$stdoutLines.', stderr lines: '.$stderrLines.'.';
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

    private function detectChangeTypes(string $ldifContent): array
    {
        $normalized = strtolower($ldifContent);
        $types = [];

        foreach (['add', 'modify', 'delete'] as $type) {
            if (str_contains($normalized, 'changetype: '.$type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

}
