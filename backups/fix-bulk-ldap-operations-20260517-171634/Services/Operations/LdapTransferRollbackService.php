<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LdapTransferRollbackService
{
    public function rollback(LdapTransferBatch $batch): array
    {
        $this->updateBatch($batch, [
            'status' => 'rollback_running',
            'message' => 'Rolling back LDAP transfer from target LDAP.',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            $target = $batch->targetLdapConnection;

            if (! $target instanceof LdapConnection) {
                throw new \RuntimeException('Target LDAP connection not found.');
            }

            $targetDns = $this->targetDnsFromMetadata($batch);

            if ($targetDns === []) {
                throw new \RuntimeException('No applied target DNs found in transfer metadata. Rollback needs successful Execute Transfer metadata.');
            }

            // Delete children first, then parents.
            usort($targetDns, fn (string $a, string $b): int => substr_count($b, ',') <=> substr_count($a, ','));

            $success = 0;
            $failed = 0;
            $skipped = 0;
            $logs = [];

            foreach ($targetDns as $dn) {
                if ($dn === '' || strtolower($dn) === strtolower((string) $target->base_dn)) {
                    $skipped++;
                    $logs[] = '[SKIPPED] Unsafe DN skipped: '.$dn;
                    continue;
                }

                if (! $this->targetExists($target, $dn)) {
                    $skipped++;
                    $logs[] = '[SKIPPED] Already missing: '.$dn;
                    continue;
                }

                $delete = $this->ldapDelete($target, $dn);

                if ($delete['ok']) {
                    $success++;
                    $logs[] = '[SUCCESS] Deleted: '.$dn;
                } else {
                    $failed++;
                    $logs[] = '[FAILED] '.$dn.' | '.$delete['error'];
                }
            }

            $status = 'rollback_success';

            if ($failed > 0 && $success > 0) {
                $status = 'rollback_partial';
            }

            if ($failed > 0 && $success === 0) {
                $status = 'rollback_failed';
            }

            $output = implode("\n", $logs);
            $outputPath = 'ldap-transfer-rollbacks/'.now()->format('Y/m/d').'/transfer-rollback-'.$batch->id.'-'.now()->format('Ymd-His').'.log';

            Storage::disk('local')->put($outputPath, $output);

            $message = match ($status) {
                'rollback_success' => 'LDAP transfer rollback completed successfully.',
                'rollback_partial' => 'LDAP transfer rollback partially completed. Check output.',
                default => 'LDAP transfer rollback failed. Check output.',
            };

            $this->updateBatch($batch, [
                'status' => $status,
                'message' => $message,
                'stdout' => $output,
                'stderr' => $failed > 0 ? $output : null,
                'output_path' => $outputPath,
                'output_size_bytes' => strlen($output),
                'output_hash' => hash('sha256', $output),
                'total_entries' => count($targetDns),
                'success_entries' => $success,
                'failed_entries' => $failed,
                'skipped_entries' => $skipped,
                'finished_at' => now(),
            ]);

            return [
                'ok' => $status !== 'rollback_failed',
                'status' => $status,
                'message' => $message,
                'total_entries' => count($targetDns),
                'success_entries' => $success,
                'failed_entries' => $failed,
                'skipped_entries' => $skipped,
            ];
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->updateBatch($batch, [
                'status' => 'rollback_failed',
                'message' => $message,
                'error_message' => $message,
                'failed_entries' => 1,
                'finished_at' => now(),
            ]);

            return [
                'ok' => false,
                'status' => 'rollback_failed',
                'message' => $message,
                'total_entries' => 0,
                'success_entries' => 0,
                'failed_entries' => 1,
                'skipped_entries' => 0,
            ];
        }
    }

    private function targetDnsFromMetadata(LdapTransferBatch $batch): array
    {
        $metadata = $batch->metadata ?? [];

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $dns = $metadata['applied_target_dns'] ?? [];

        if (! is_array($dns)) {
            return [];
        }

        return collect($dns)
            ->map(fn ($dn): string => trim((string) $dn))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function targetExists(LdapConnection $target, string $dn): bool
    {
        $process = new Process([
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($target),
            '-D',
            (string) $target->bind_dn,
            '-w',
            (string) $target->bind_password,
            '-b',
            $dn,
            '-s',
            'base',
            '(objectClass=*)',
            'dn',
        ], base_path());

        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), 'dn:');
    }

    private function ldapDelete(LdapConnection $target, string $dn): array
    {
        $process = new Process([
            'ldapdelete',
            '-x',
            '-H',
            $this->ldapUri($target),
            '-D',
            (string) $target->bind_dn,
            '-w',
            (string) $target->bind_password,
            $dn,
        ], base_path());

        $process->setTimeout(120);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'error' => trim($process->getErrorOutput()) ?: trim($process->getOutput()),
        ];
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ((bool) ($connection->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function updateBatch(LdapTransferBatch $batch, array $data): void
    {
        if (! Schema::hasTable($batch->getTable())) {
            return;
        }

        $columns = Schema::getColumnListing($batch->getTable());
        $clean = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }

            if (in_array($key, ['metadata', 'options', 'excluded_attributes'], true) && is_array($value)) {
                $clean[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                continue;
            }

            $clean[$key] = $value;
        }

        if (in_array('updated_at', $columns, true)) {
            $clean['updated_at'] = now();
        }

        if ($clean !== []) {
            DB::table($batch->getTable())
                ->where('id', $batch->id)
                ->update($clean);
        }
    }
}
