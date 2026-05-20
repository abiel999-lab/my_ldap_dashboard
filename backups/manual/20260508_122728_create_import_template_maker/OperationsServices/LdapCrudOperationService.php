<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\LdapCrudOperation;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class LdapCrudOperationService
{
    public function preview(LdapCrudOperation $operation): array
    {
        $startedAt = microtime(true);

        $operation->loadMissing('ldapConnection');

        $errors = app(LdapCrudValidator::class)->validate($operation);
        $ldif = app(LdapCrudLdifBuilder::class)->build($operation);

        $commandPreview = $this->displayCommand($operation->ldapConnection, '[PREVIEW_LDIF_FILE]');

        $status = $errors === [] ? 'previewed' : 'preview_failed';

        $operation->forceFill([
            'status' => $status,
            'validation_errors' => $errors,
            'ldif_preview' => $ldif,
            'command_preview' => $commandPreview,
            'previewed_at' => now(),
            'message' => $errors === [] ? 'LDAP CRUD preview generated successfully.' : 'LDAP CRUD preview generated with validation errors.',
            'error_message' => $errors === [] ? null : implode(PHP_EOL, $errors),
            'safe_mode' => true,
            'dry_run' => true,
            'destructive' => (bool) $operation->destructive,
        ])->save();

        app(AuditLogger::class)->log([
            'module' => 'operations.ldap_crud',
            'action' => 'preview_ldap_crud_operation',
            'status' => $errors === [] ? 'success' : 'failed',
            'target_type' => LdapCrudOperation::class,
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->target_dn,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'request_payload' => RedactsSensitiveData::redact([
                'operation_type' => $operation->operation_type,
                'target_dn' => $operation->target_dn,
                'new_rdn' => $operation->new_rdn,
                'parent_dn' => $operation->parent_dn,
                'dry_run' => true,
                'ldap_will_change' => false,
            ]),
            'after_value' => [
                'validation_errors' => $errors,
                'ldif_preview' => $ldif,
            ],
            'duration_ms' => $this->durationMs($startedAt),
            'error_message' => $errors === [] ? null : implode(PHP_EOL, $errors),
        ]);

        return [
            'ok' => $errors === [],
            'message' => $operation->message,
            'errors' => $errors,
            'ldif_preview' => $ldif,
            'command_preview' => $commandPreview,
        ];
    }

    public function dryRun(LdapCrudOperation $operation): array
    {
        $startedAt = microtime(true);

        $operation->loadMissing('ldapConnection');

        $preview = $this->preview($operation);

        if (! $preview['ok']) {
            return [
                'ok' => false,
                'message' => 'Dry-run blocked by validation errors.',
                'errors' => $preview['errors'],
            ];
        }

        $ldifPath = $this->writeLdifFile($operation, 'dry_run');
        $command = $this->buildCommand($operation->ldapConnection, $ldifPath, true);
        $displayCommand = $this->displayCommand($operation->ldapConnection, $ldifPath);

        $execution = $this->createExecution($operation, 'ldap_crud_dry_run', $displayCommand, true, false);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            $ok = $process->isSuccessful();

            $execution->forceFill([
                'status' => $ok ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'error_message' => $ok ? null : 'LDAP CRUD dry-run command exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $operation->forceFill([
                'status' => $ok ? 'dry_run_success' : 'dry_run_failed',
                'preview_command_execution_id' => $execution->id,
                'message' => $ok ? 'LDAP CRUD dry-run completed successfully. LDAP data was not changed.' : 'LDAP CRUD dry-run failed. LDAP data was not changed.',
                'error_message' => $ok ? null : ($stderr ?: 'Dry-run failed.'),
                'failed_at' => $ok ? null : now(),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.ldap_crud',
                'action' => 'dry_run_ldap_crud_operation',
                'status' => $ok ? 'success' : 'failed',
                'target_type' => LdapCrudOperation::class,
                'target_key' => (string) $operation->id,
                'target_dn' => $operation->target_dn,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'command_execution_id' => $execution->id,
                'command' => $displayCommand,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $ok ? null : ($stderr ?: 'Dry-run failed.'),
                'request_payload' => [
                    'dry_run' => true,
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => $ok,
                'message' => $operation->message,
                'command_execution_id' => $execution->id,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $operation->forceFill([
                'status' => 'dry_run_failed',
                'preview_command_execution_id' => $execution->id,
                'message' => 'LDAP CRUD dry-run failed.',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'command_execution_id' => $execution->id,
            ];
        }
    }


    public function applyReal(LdapCrudOperation $operation): array
    {
        $startedAt = microtime(true);

        $operation->loadMissing('ldapConnection');

        if ($operation->status !== 'dry_run_success') {
            $message = 'Real LDAP apply is blocked. Run successful dry-run first.';

            $operation->forceFill([
                'status' => 'apply_blocked',
                'message' => $message,
                'error_message' => $message,
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.ldap_crud',
                'action' => 'apply_real_ldap_crud_operation',
                'status' => 'blocked',
                'target_type' => LdapCrudOperation::class,
                'target_key' => (string) $operation->id,
                'target_dn' => $operation->target_dn,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'error_message' => $message,
                'request_payload' => [
                    'dry_run_required' => true,
                    'current_status' => $operation->status,
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => false,
                'message' => $message,
            ];
        }

        $preview = $this->preview($operation);

        if (! $preview['ok']) {
            return [
                'ok' => false,
                'message' => 'Apply blocked by validation errors.',
                'errors' => $preview['errors'],
            ];
        }

        $ldifPath = $this->writeLdifFile($operation, 'real_apply');
        $command = $this->buildCommand($operation->ldapConnection, $ldifPath, false);
        $displayCommand = $this->displayApplyCommand($operation->ldapConnection, $ldifPath);

        $execution = $this->createExecution($operation, 'ldap_crud_real_apply', $displayCommand, false, true);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());
            $ok = $process->isSuccessful();

            $execution->forceFill([
                'status' => $ok ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'error_message' => $ok ? null : 'LDAP CRUD real apply command exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $operation->forceFill([
                'status' => $ok ? 'applied' : 'apply_failed',
                'apply_command_execution_id' => $execution->id,
                'applied_at' => $ok ? now() : null,
                'failed_at' => $ok ? null : now(),
                'message' => $ok
                    ? 'LDAP CRUD real apply completed successfully. LDAP data has been changed.'
                    : 'LDAP CRUD real apply failed. Check command execution output.',
                'error_message' => $ok ? null : ($stderr ?: 'Real LDAP apply failed.'),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.ldap_crud',
                'action' => 'apply_real_ldap_crud_operation',
                'status' => $ok ? 'success' : 'failed',
                'target_type' => LdapCrudOperation::class,
                'target_key' => (string) $operation->id,
                'target_dn' => $operation->target_dn,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'command_execution_id' => $execution->id,
                'command' => $displayCommand,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $ok ? null : ($stderr ?: 'Real LDAP apply failed.'),
                'request_payload' => [
                    'dry_run' => false,
                    'destructive' => true,
                    'ldap_was_changed' => $ok,
                ],
            ]);

            if ($ok && class_exists(\App\Services\Directory\LdapDirectoryExplorerSyncService::class)) {
                try {
                    app(\App\Services\Directory\LdapDirectoryExplorerSyncService::class)->sync($operation->ldapConnection);
                } catch (Throwable $syncException) {
                    app(AuditLogger::class)->log([
                        'module' => 'operations.ldap_crud',
                        'action' => 'post_apply_directory_explorer_sync',
                        'status' => 'failed',
                        'target_type' => LdapCrudOperation::class,
                        'target_key' => (string) $operation->id,
                        'target_dn' => $operation->target_dn,
                        'ldap_connection_id' => $operation->ldap_connection_id,
                        'error_message' => $syncException->getMessage(),
                    ]);
                }
            }

            return [
                'ok' => $ok,
                'message' => $operation->message,
                'command_execution_id' => $execution->id,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $operation->forceFill([
                'status' => 'apply_failed',
                'apply_command_execution_id' => $execution->id,
                'message' => 'LDAP CRUD real apply failed.',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.ldap_crud',
                'action' => 'apply_real_ldap_crud_operation',
                'status' => 'failed',
                'target_type' => LdapCrudOperation::class,
                'target_key' => (string) $operation->id,
                'target_dn' => $operation->target_dn,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'command_execution_id' => $execution->id,
                'error_message' => $exception->getMessage(),
                'request_payload' => [
                    'dry_run' => false,
                    'destructive' => true,
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'command_execution_id' => $execution->id,
            ];
        }
    }

    private function displayApplyCommand(?LdapConnection $connection, string $ldifPath): string
    {
        if (! $connection) {
            return 'ldapmodify [NO_CONNECTION]';
        }

        return 'ldapmodify -v -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -f '.$ldifPath;
    }


    private function createExecution(
        LdapCrudOperation $operation,
        string $commandType,
        string $displayCommand,
        bool $safeMode,
        bool $destructive,
    ): CommandExecution {
        $actor = Auth::user();

        return CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'operations.ldap_crud',
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $displayCommand,
            'working_directory' => base_path(),
            'environment_context' => [
                'ldap_crud_operation_id' => $operation->id,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'operation_type' => $operation->operation_type,
                'target_dn' => $operation->target_dn,
                'safe_mode' => $safeMode,
                'destructive' => $destructive,
            ],
            'safe_mode' => $safeMode,
            'preview_mode' => true,
            'destructive' => $destructive,
            'started_at' => now(),
        ]);
    }

    private function writeLdifFile(LdapCrudOperation $operation, string $purpose): string
    {
        $filename = now()->format('Ymd_His').'_ldap_crud_'.$purpose.'_operation_'.$operation->id.'.ldif';
        $path = 'operations/ldap-crud/'.$filename;

        Storage::disk('local')->put($path, $operation->ldif_preview ?: app(LdapCrudLdifBuilder::class)->build($operation));

        return storage_path('app/private/'.$path);
    }

    private function buildCommand(?LdapConnection $connection, string $ldifPath, bool $dryRun): array
    {
        if (! $connection) {
            return ['false'];
        }

        $command = [
            'ldapmodify',
            '-v',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
        ];

        if ($dryRun) {
            $command[] = '-n';
        }

        $command[] = '-f';
        $command[] = $ldifPath;

        return $command;
    }

    private function displayCommand(?LdapConnection $connection, string $ldifPath): string
    {
        if (! $connection) {
            return 'ldapmodify [NO_CONNECTION]';
        }

        return 'ldapmodify -v -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -n'
            .' -f '.$ldifPath;
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
            '/(userPassword:\s*)(.+)/i',
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
