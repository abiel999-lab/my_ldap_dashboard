<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\CommandExecution;
use App\Models\Operations\LdifExportBatch;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
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
            'command' => $this->displayCommand($batch),
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldif_export_batch_id' => $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'export_scope' => $batch->export_scope,
                'search_scope' => $batch->search_scope,
                'base_dn' => $batch->base_dn,
                'effective_base_dn' => $batch->effective_base_dn,
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

            $batch->forceFill([
                'status' => 'failed',
                'message' => $validation['message'],
                'command_execution_id' => $execution->id,
                'finished_at' => now(),
            ])->save();

            $this->audit($batch, $execution, 'failed');

            return $execution;
        }

        try {
            $command = $this->buildCommand($batch);
            $process = new Process($command, base_path());
            $process->setTimeout(180);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $ok = $process->isSuccessful();

            $outputPath = null;
            $outputSize = null;
            $outputHash = null;

            if ($ok) {
                $outputPath = $this->storeOutput($batch, $stdout);
                $outputSize = strlen($stdout);
                $outputHash = hash('sha256', $stdout);
            }

            $execution->forceFill([
                'status' => $ok ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
                'error_message' => $ok ? null : ($stderr ?: 'ldapsearch exited with non-zero status.'),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $batch->forceFill([
                'status' => $ok ? 'success' : 'failed',
                'command_execution_id' => $execution->id,
                'output_path' => $outputPath,
                'output_size_bytes' => $outputSize,
                'output_hash' => $outputHash,
                'message' => $ok
                    ? 'LDIF export completed successfully.'
                    : ($stderr ?: 'LDIF export failed.'),
                'finished_at' => now(),
            ])->save();

            $this->audit($batch, $execution, $ok ? 'success' : 'failed');

            return $execution;
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $exception->getMessage(),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $batch->forceFill([
                'status' => 'failed',
                'command_execution_id' => $execution->id,
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->audit($batch, $execution, 'failed');

            return $execution;
        }
    }

    private function validateExport(LdifExportBatch $batch): array
    {
        $connection = $this->connection($batch);

        if (! $connection) {
            return ['ok' => false, 'message' => 'LDAP connection not found.'];
        }

        if (! $connection->is_active) {
            return ['ok' => false, 'message' => 'Selected LDAP connection is not active.'];
        }

        if (blank($connection->bind_dn) || blank($connection->bind_password)) {
            return ['ok' => false, 'message' => 'LDAP connection bind DN/password is missing.'];
        }

        if (blank($batch->effective_base_dn)) {
            return ['ok' => false, 'message' => 'Effective export DN is empty.'];
        }

        if (blank($batch->filter)) {
            return ['ok' => false, 'message' => 'LDAP filter is required.'];
        }

        $filter = trim((string) $batch->filter);

        if (! str_starts_with($filter, '(') || ! str_ends_with($filter, ')')) {
            return ['ok' => false, 'message' => 'LDAP filter must start with ( and end with ). Example: (objectClass=*)'];
        }

        if (! in_array((string) $batch->search_scope, ['base', 'one', 'sub'], true)) {
            return ['ok' => false, 'message' => 'Search scope must be base, one, or sub.'];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    private function buildCommand(LdifExportBatch $batch): array
    {
        $connection = $this->connection($batch);

        $command = [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($connection),
            '-D',
            (string) $connection->bind_dn,
            '-w',
            (string) $connection->bind_password,
            '-b',
            (string) $batch->effective_base_dn,
            '-s',
            (string) ($batch->search_scope ?: 'sub'),
            '-z',
            (string) ((int) ($batch->size_limit ?: 500)),
            (string) $batch->filter,
        ];

        foreach ($batch->attribute_list as $attribute) {
            if ($attribute !== '') {
                $command[] = $attribute;
            }
        }

        return $command;
    }

    private function displayCommand(LdifExportBatch $batch): string
    {
        $connection = $this->connection($batch);

        return implode(' ', [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o ldif-wrap=no',
            '-H '.$this->ldapUri($connection),
            '-D '.(string) $connection?->bind_dn,
            '-w [REDACTED]',
            '-b '.escapeshellarg((string) $batch->effective_base_dn),
            '-s '.(string) ($batch->search_scope ?: 'sub'),
            '-z '.(string) ((int) ($batch->size_limit ?: 500)),
            escapeshellarg((string) $batch->filter),
            implode(' ', $batch->attribute_list),
        ]);
    }

    private function connection(LdifExportBatch $batch): ?LdapConnection
    {
        if ($batch->ldapConnection) {
            return $batch->ldapConnection;
        }

        if (filled($batch->ldap_connection_id)) {
            return LdapConnection::query()->find($batch->ldap_connection_id);
        }

        return LdapConnection::query()->where('is_default', true)->first();
    }

    private function ldapUri(?LdapConnection $connection): string
    {
        if (! $connection) {
            return 'ldap://127.0.0.1:389';
        }

        $scheme = $connection->use_ssl ? 'ldaps' : 'ldap';

        return $scheme.'://'.$connection->host.':'.$connection->port;
    }

    private function storeOutput(LdifExportBatch $batch, string $content): string
    {
        $disk = 'local';

        $directory = 'ldif-exports/'.now()->format('Y/m/d');
        $filename = 'ldif-export-'.$batch->getKey().'-'.now()->format('Ymd-His').'.ldif';
        $path = $directory.'/'.$filename;

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        Storage::disk($disk)->put($path, $content);

        clearstatcache();

        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('LDIF export failed: output file was not written. disk='.$disk.' path='.$path);
        }

        $size = Storage::disk($disk)->size($path);

        if ($size <= 0) {
            Storage::disk($disk)->delete($path);

            throw new \RuntimeException('LDIF export failed: output file is empty. disk='.$disk.' path='.$path);
        }

        return $path;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function audit(LdifExportBatch $batch, CommandExecution $execution, string $status): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.export',
            'action' => 'execute_ldif_export',
            'status' => $status,
            'target_type' => LdifExportBatch::class,
            'target_key' => (string) $batch->id,
            'target_dn' => $batch->effective_base_dn,
            'ldap_connection_id' => $batch->ldap_connection_id,
            'operation_job_id' => $batch->operation_job_id,
            'command_execution_id' => $execution->id,
            'request_payload' => [
                'export_scope' => $batch->export_scope,
                'search_scope' => $batch->search_scope,
                'base_dn' => $batch->base_dn,
                'effective_base_dn' => $batch->effective_base_dn,
                'filter' => $batch->filter,
                'attributes' => $batch->attribute_list,
                'size_limit' => $batch->size_limit,
            ],
            'error_message' => $execution->error_message,
        ]);
    }
}
