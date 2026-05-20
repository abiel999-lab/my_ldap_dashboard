<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\LdifExportBatch;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class LdifExportExecutor
{
    public function execute(LdifExportBatch $batch): CommandExecution
    {
        $startedAt = microtime(true);
        $user = Auth::user();

        $validation = $this->validateExport($batch);

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),

            'module' => 'operations.export',
            'command_type' => 'ldif_export',
            'status' => $validation['ok'] ? 'running' : 'blocked',
            'command' => $this->redactString($this->displayCommand($batch)),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldif_export_batch_id' => $batch->id,
                'name' => $batch->name,
                'base_dn' => $batch->base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
                'safe_mode' => true,
                'destructive' => false,
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

            $this->audit($batch, $execution, 'failed');

            return $execution;
        }

        try {
            $command = $this->buildCommand($batch);
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $outputPath = null;
            $outputSize = null;
            $outputHash = null;

            if ($process->isSuccessful()) {
                $outputPath = $this->writeExportFile($batch, $stdout);
                $absolutePath = Storage::disk('local')->path($outputPath);
                $outputSize = File::exists($absolutePath) ? File::size($absolutePath) : 0;
                $outputHash = File::exists($absolutePath) ? hash_file('sha256', $absolutePath) : null;

                $batch->forceFill([
                    'status' => 'success',
                    'output_path' => $outputPath,
                    'output_size_bytes' => $outputSize,
                    'output_hash' => $outputHash,
                    'message' => 'LDIF export completed successfully.',
                    'command_execution_id' => $execution->id,
                    'finished_at' => now(),
                    'metadata' => array_merge($batch->metadata ?? [], [
                        'exported_bytes' => $outputSize,
                        'output_hash' => $outputHash,
                    ]),
                ])->save();
            }

            $execution->forceFill([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'stdout' => $this->redactString($this->summarizeStdout($stdout, $outputPath, $outputSize)),
                'stderr' => $this->redactString($stderr),
                'exit_code' => $process->getExitCode(),
                'error_message' => $process->isSuccessful() ? null : 'LDIF export command exited with non-zero status.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            if (! $process->isSuccessful()) {
                $batch->forceFill([
                    'status' => 'failed',
                    'message' => $execution->error_message,
                    'command_execution_id' => $execution->id,
                    'finished_at' => now(),
                ])->save();
            }

            $this->audit($batch, $execution, $execution->status === 'success' ? 'success' : 'failed');

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

            $batch->forceFill([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'command_execution_id' => $execution->id,
                'finished_at' => now(),
            ])->save();

            $this->audit($batch, $execution, 'failed');

            return $execution;
        }
    }

    public function validateExport(LdifExportBatch $batch): array
    {
        if ($batch->destructive) {
            return [
                'ok' => false,
                'message' => 'LDIF export must not be destructive.',
            ];
        }

        if (blank($batch->base_dn)) {
            return [
                'ok' => false,
                'message' => 'Base DN is required.',
            ];
        }

        if (blank($batch->filter)) {
            return [
                'ok' => false,
                'message' => 'LDAP filter is required.',
            ];
        }

        if (! str_starts_with(trim($batch->filter), '(') || ! str_ends_with(trim($batch->filter), ')')) {
            return [
                'ok' => false,
                'message' => 'LDAP filter must be wrapped in parentheses.',
            ];
        }

        $blocked = ['ldapmodify', 'ldapadd', 'ldapdelete', 'rm -rf', 'sudo', 'mkfs', 'chmod 777'];

        foreach ($blocked as $needle) {
            if (str_contains(strtolower((string) $batch->filter), strtolower($needle))) {
                return [
                    'ok' => false,
                    'message' => 'Blocked unsafe pattern in filter.',
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'LDIF export validation passed.',
        ];
    }

    private function buildCommand(LdifExportBatch $batch): array
    {
        $connection = $batch->ldapConnection;

        if (! $connection && filled($batch->ldap_connection_id)) {
            $connection = LdapConnection::query()->find($batch->ldap_connection_id);
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        if (! $connection) {
            throw new \RuntimeException('No LDAP connection found for LDIF export.');
        }

        if (blank($connection->bind_dn) || blank($connection->bind_password)) {
            throw new \RuntimeException('LDAP connection does not have bind DN/password configured.');
        }

        return [
            'ldapsearch',
            '-LLL',
            '-x',
            '-z',
            (string) max(1, (int) $batch->size_limit),
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-b',
            $batch->base_dn,
            $batch->filter,
            ...$batch->attribute_list,
        ];
    }

    private function displayCommand(LdifExportBatch $batch): string
    {
        $connection = $batch->ldapConnection;

        if (! $connection && filled($batch->ldap_connection_id)) {
            $connection = LdapConnection::query()->find($batch->ldap_connection_id);
        }

        if (! $connection) {
            $connection = LdapConnection::query()->where('is_default', true)->first();
        }

        return 'ldapsearch -LLL -x -z '.max(1, (int) $batch->size_limit)
            .' -H ldap://'.($connection?->host ?? 'default').':'.($connection?->port ?? '389')
            .' -D '.($connection?->bind_dn ?? 'default')
            .' -w [REDACTED]'
            .' -b '.$batch->base_dn
            .' '.$batch->filter
            .' '.implode(' ', $batch->attribute_list);
    }

    private function writeExportFile(LdifExportBatch $batch, string $content): string
    {
        $safeName = str($batch->name)
            ->slug('_')
            ->limit(80, '')
            ->toString();

        $path = 'exports/ldif/'.now()->format('Ymd_His').'_batch_'.$batch->id.'_'.$safeName.'.ldif';

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function summarizeStdout(string $stdout, ?string $outputPath, ?int $outputSize): string
    {
        $lineCount = substr_count($stdout, "\n");

        return "LDIF export output saved.\n"
            .'Path: '.($outputPath ?? 'N/A')."\n"
            .'Size bytes: '.($outputSize ?? 0)."\n"
            .'Line count: '.$lineCount."\n";
    }

    private function audit(LdifExportBatch $batch, CommandExecution $execution, string $status): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.export',
            'action' => 'execute_ldif_export',
            'status' => $status === 'success' ? 'success' : 'failed',
            'target_type' => LdifExportBatch::class,
            'target_key' => (string) $batch->id,
            'operation_job_id' => $batch->operation_job_id,
            'request_payload' => [
                'name' => $batch->name,
                'base_dn' => $batch->base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
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
