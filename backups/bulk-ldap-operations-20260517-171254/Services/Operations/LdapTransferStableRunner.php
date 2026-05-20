<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LdapTransferStableRunner
{
    public function preview(LdapTransferBatch $batch): array
    {
        $operationJobId = $this->createOperationJob($batch, 'ldap_transfer_preview', 'LDAP Transfer Preview - '.$batch->name);

        $this->updateBatch($batch, [
            'status' => 'running',
            'message' => 'Generating LDAP transfer preview.',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            $source = $batch->sourceLdapConnection;
            $target = $batch->targetLdapConnection;

            if (! $source instanceof LdapConnection) {
                throw new \RuntimeException('Source LDAP connection not found.');
            }

            if (! $target instanceof LdapConnection) {
                throw new \RuntimeException('Target LDAP connection not found.');
            }

            $sourceDn = $this->effectiveSourceDn($batch);

            if ($sourceDn === '') {
                throw new \RuntimeException('Effective source DN is empty.');
            }

            $targetParentDn = $this->targetParentDn($batch);

            if ($targetParentDn === '') {
                throw new \RuntimeException('Target parent DN is empty.');
            }

            $entries = $this->searchSourceEntries($batch, $source, $sourceDn);
            $previewLdif = $this->buildPreviewLdif($batch, $entries, $sourceDn, $targetParentDn, $target);

            $outputPath = 'ldap-transfer-previews/'.now()->format('Y/m/d').'/transfer-preview-'.$batch->id.'-'.now()->format('Ymd-His').'.ldif';

            Storage::disk('local')->put($outputPath, $previewLdif);

            $result = [
                'status' => 'previewed',
                'message' => 'LDAP transfer preview generated successfully. Nothing was written to target LDAP.',
                'preview_ldif' => $previewLdif,
                'output_path' => $outputPath,
                'output_size_bytes' => strlen($previewLdif),
                'output_hash' => hash('sha256', $previewLdif),
                'total_entries' => count($entries),
                'success_entries' => count($entries),
                'planned_entries' => count($entries),
                'transferred_entries' => 0,
                'failed_entries' => 0,
                'skipped_entries' => 0,
                'finished_at' => now(),
                'operation_job_id' => $operationJobId,
            ];

            $this->updateBatch($batch, $result);

            $this->createOperationLog($operationJobId, 'info', 'LDAP transfer preview generated successfully.', [
                'batch_id' => $batch->id,
                'total_entries' => count($entries),
                'output_path' => $outputPath,
            ]);

            $this->finishOperationJob($operationJobId, 'success', count($entries), count($entries), 0, 0, null);

            return [
                'ok' => true,
                'message' => $result['message'],
                'total_entries' => count($entries),
                'success_entries' => count($entries),
                'failed_entries' => 0,
                'skipped_entries' => 0,
            ];
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->updateBatch($batch, [
                'status' => 'failed',
                'message' => $message,
                'error_message' => $message,
                'failed_entries' => 1,
                'finished_at' => now(),
                'operation_job_id' => $operationJobId,
            ]);

            $this->createOperationLog($operationJobId, 'error', 'LDAP transfer preview failed.', [
                'batch_id' => $batch->id,
                'error' => $message,
            ]);

            $this->finishOperationJob($operationJobId, 'failed', 1, 0, 1, 0, $message);

            return [
                'ok' => false,
                'message' => $message,
                'total_entries' => 0,
                'success_entries' => 0,
                'failed_entries' => 1,
                'skipped_entries' => 0,
            ];
        }
    }

    public function execute(LdapTransferBatch $batch): array
    {
        $operationJobId = $this->createOperationJob($batch, 'ldap_transfer_execute', 'LDAP Transfer Execute - '.$batch->name);

        $this->updateBatch($batch, [
            'status' => 'running',
            'message' => 'Executing LDAP transfer to target LDAP.',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            $source = $batch->sourceLdapConnection;
            $target = $batch->targetLdapConnection;

            if (! $source instanceof LdapConnection) {
                throw new \RuntimeException('Source LDAP connection not found.');
            }

            if (! $target instanceof LdapConnection) {
                throw new \RuntimeException('Target LDAP connection not found.');
            }

            $sourceDn = $this->effectiveSourceDn($batch);

            if ($sourceDn === '') {
                throw new \RuntimeException('Effective source DN is empty.');
            }

            $targetParentDn = $this->targetParentDn($batch);

            if ($targetParentDn === '') {
                throw new \RuntimeException('Target parent DN is empty.');
            }

            $entries = $this->searchSourceEntries($batch, $source, $sourceDn);

            $success = 0;
            $failed = 0;
            $skipped = 0;
            $lines = [];
            $appliedTargetDns = [];

            foreach ($entries as $entry) {
                $targetDn = $this->targetDnFor($batch, $entry['dn'], $sourceDn, $targetParentDn);

                if ($this->targetExists($target, $targetDn)) {
                    $skipped++;
                    $lines[] = '[SKIPPED] Target already exists: '.$targetDn;
                    continue;
                }

                $ldif = $this->buildSafeAddLdif($batch, $entry, $targetDn);
                $apply = $this->ldapAdd($target, $ldif);

                if ($apply['ok']) {
                    $success++;
                    $appliedTargetDns[] = $targetDn;
                    $lines[] = '[SUCCESS] Added: '.$targetDn;
                } else {
                    $failed++;
                    $lines[] = '[FAILED] '.$targetDn.' | '.$apply['error'];
                }
            }

            $status = 'success';

            if ($failed > 0 && $success > 0) {
                $status = 'partial_success';
            }

            if ($failed > 0 && $success === 0) {
                $status = 'failed';
            }

            $output = implode("\n", $lines);

            $outputPath = 'ldap-transfer-executions/'.now()->format('Y/m/d').'/transfer-execute-'.$batch->id.'-'.now()->format('Ymd-His').'.log';

            Storage::disk('local')->put($outputPath, $output);

            $message = match ($status) {
                'success' => 'LDAP transfer executed successfully.',
                'partial_success' => 'LDAP transfer partially succeeded. Check output.',
                default => 'LDAP transfer failed. Check output.',
            };

            $this->updateBatch($batch, [
                'status' => $status,
                'message' => $message,
                'stdout' => $output,
                'stderr' => $failed > 0 ? $output : null,
                'output_path' => $outputPath,
                'output_size_bytes' => strlen($output),
                'output_hash' => hash('sha256', $output),
                'total_entries' => count($entries),
                'success_entries' => $success,
                'planned_entries' => count($entries),
                'transferred_entries' => $success,
                'failed_entries' => $failed,
                'skipped_entries' => $skipped,
                'metadata' => [
                    'applied_target_dns' => $appliedTargetDns,
                    'source_dn' => $sourceDn,
                    'target_parent_dn' => $targetParentDn,
                ],
                'finished_at' => now(),
                'operation_job_id' => $operationJobId,
            ]);

            $this->createOperationLog($operationJobId, $status === 'success' ? 'info' : 'warning', $message, [
                'batch_id' => $batch->id,
                'total_entries' => count($entries),
                'success_entries' => $success,
                'failed_entries' => $failed,
                'skipped_entries' => $skipped,
                'output_path' => $outputPath,
            ]);

            $this->finishOperationJob($operationJobId, $status, count($entries), $success, $failed, $skipped, $status === 'failed' ? $message : null);

            return [
                'ok' => $status !== 'failed',
                'status' => $status,
                'message' => $message,
                'total_entries' => count($entries),
                'success_entries' => $success,
                'failed_entries' => $failed,
                'skipped_entries' => $skipped,
            ];
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->updateBatch($batch, [
                'status' => 'failed',
                'message' => $message,
                'error_message' => $message,
                'failed_entries' => 1,
                'finished_at' => now(),
                'operation_job_id' => $operationJobId,
            ]);

            $this->createOperationLog($operationJobId, 'error', 'LDAP transfer execute failed.', [
                'batch_id' => $batch->id,
                'error' => $message,
            ]);

            $this->finishOperationJob($operationJobId, 'failed', 1, 0, 1, 0, $message);

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $message,
                'total_entries' => 0,
                'success_entries' => 0,
                'failed_entries' => 1,
                'skipped_entries' => 0,
            ];
        }
    }

    private function searchSourceEntries(LdapTransferBatch $batch, LdapConnection $source, string $sourceDn): array
    {
        $filter = $this->value($batch, ['filter', 'ldap_filter'], '(objectClass=*)');
        $scope = $this->value($batch, ['search_scope', 'scope'], 'sub');
        $sizeLimit = (int) ($batch->size_limit ?: 1000);
        $pageSize = (int) ($batch->page_size ?: 500);

        $attributes = $this->value($batch, ['attributes'], '*');
        $attributeList = collect(preg_split('/[\s,]+/', $attributes) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        if ($attributeList === []) {
            $attributeList = ['*'];
        }

        $command = [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($source),
            '-D',
            (string) $source->bind_dn,
            '-w',
            (string) $source->bind_password,
            '-b',
            $sourceDn,
            '-s',
            $scope,
            '-z',
            (string) $sizeLimit,
            '-E',
            'pr='.$pageSize.'/noprompt',
            $filter,
        ];

        foreach ($attributeList as $attribute) {
            $command[] = $attribute;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ldapsearch failed.');
        }

        return $this->parseLdif($process->getOutput());
    }

    private function parseLdif(string $ldif): array
    {
        $ldif = str_replace(["\r\n", "\r"], "\n", $ldif);
        $blocks = preg_split("/\n\s*\n/", trim($ldif));
        $entries = [];

        foreach ($blocks as $block) {
            if (trim($block) === '') {
                continue;
            }

            $lines = explode("\n", $block);
            $normalized = [];

            foreach ($lines as $line) {
                if (str_starts_with($line, ' ') && $normalized !== []) {
                    $normalized[count($normalized) - 1] .= substr($line, 1);
                    continue;
                }

                $normalized[] = $line;
            }

            $dn = null;
            $attributes = [];

            foreach ($normalized as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = ltrim($value);

                if (str_starts_with($value, ':')) {
                    $value = base64_decode(trim(substr($value, 1))) ?: trim($value);
                } else {
                    $value = trim($value);
                }

                if ($key === 'dn') {
                    $dn = $value;
                    continue;
                }

                $attributes[$key] ??= [];
                $attributes[$key][] = $value;
            }

            if ($dn) {
                $entries[] = [
                    'dn' => $dn,
                    'attributes' => $attributes,
                ];
            }
        }

        return $entries;
    }

    private function buildPreviewLdif(LdapTransferBatch $batch, array $entries, string $sourceDn, string $targetParentDn, LdapConnection $target): string
    {
        $lines = [
            '# LDAP Transfer Preview LDIF',
            '# Target LDAP: '.$target->name,
            '# Source DN: '.$sourceDn,
            '# Target Parent DN: '.$targetParentDn,
            '# Strategy: '.$this->value($batch, ['target_dn_strategy'], 'flatten'),
            '# Generated At: '.now()->toDateTimeString(),
            '',
        ];

        if ($entries === []) {
            $lines[] = '# No entries matched source/filter.';
            return implode("\n", $lines);
        }

        foreach ($entries as $entry) {
            $targetDn = $this->targetDnFor($batch, $entry['dn'], $sourceDn, $targetParentDn);

            $lines[] = '# ------------------------------------------------------------';
            $lines[] = '# Source DN: '.$entry['dn'];
            $lines[] = '# Target DN: '.$targetDn;
            $lines[] = '# ------------------------------------------------------------';
            $lines[] = $this->buildSafeAddLdif($batch, $entry, $targetDn);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function buildSafeAddLdif(LdapTransferBatch $batch, array $entry, string $targetDn): string
    {
        $attributes = $entry['attributes'];
        $classes = collect($attributes['objectClass'] ?? $attributes['objectclass'] ?? [])
            ->map(fn ($item): string => strtolower((string) $item))
            ->values()
            ->all();

        $rdn = $this->rdn($targetDn);
        [$rdnAttr, $rdnValue] = array_pad(explode('=', $rdn, 2), 2, '');

        $lines = [
            'dn: '.$targetDn,
            'changetype: add',
        ];

        if (strtolower($rdnAttr) === 'ou' || in_array('organizationalunit', $classes, true)) {
            $lines[] = 'objectClass: top';
            $lines[] = 'objectClass: organizationalUnit';
            $lines[] = 'ou: '.$this->firstValue($attributes, ['ou'], $rdnValue);
            $lines[] = '';

            return implode("\n", $lines);
        }

        if (strtolower($rdnAttr) === 'uid' || in_array('inetorgperson', $classes, true) || in_array('person', $classes, true)) {
            $uid = $this->firstValue($attributes, ['uid'], $rdnValue);
            $cn = $this->firstValue($attributes, ['cn', 'displayName'], $uid);
            $sn = $this->firstValue($attributes, ['sn'], $cn ?: $uid);

            $lines[] = 'objectClass: top';
            $lines[] = 'objectClass: person';
            $lines[] = 'objectClass: organizationalPerson';
            $lines[] = 'objectClass: inetOrgPerson';
            $lines[] = 'cn: '.$cn;
            $lines[] = 'sn: '.$sn;

            if ($uid !== '') {
                $lines[] = 'uid: '.$uid;
            }

            foreach (['mail', 'givenName', 'displayName', 'description', 'telephoneNumber'] as $attribute) {
                foreach ($this->valuesFor($attributes, $attribute) as $value) {
                    if ($value !== '') {
                        $lines[] = $attribute.': '.$this->escapeLdifValue($value);
                    }
                }
            }

            $lines[] = '';

            return implode("\n", $lines);
        }

        $lines[] = 'objectClass: top';
        $lines[] = 'objectClass: organizationalUnit';
        $lines[] = 'ou: '.$rdnValue;
        $lines[] = '';

        return implode("\n", $lines);
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

    private function ldapAdd(LdapConnection $target, string $ldif): array
    {
        $dir = storage_path('app/ldap-transfer-runtime');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/add-'.Str::uuid().'.ldif';

        file_put_contents($file, $ldif);

        $process = new Process([
            'ldapadd',
            '-x',
            '-H',
            $this->ldapUri($target),
            '-D',
            (string) $target->bind_dn,
            '-w',
            (string) $target->bind_password,
            '-f',
            $file,
        ], base_path());

        $process->setTimeout(120);
        $process->run();

        @unlink($file);

        return [
            'ok' => $process->isSuccessful(),
            'error' => trim($process->getErrorOutput()) ?: trim($process->getOutput()),
        ];
    }

    private function effectiveSourceDn(LdapTransferBatch $batch): string
    {
        $scope = $this->value($batch, ['transfer_scope'], 'custom_dn');
        $baseDn = $this->value($batch, ['source_base_dn'], '');

        if ($scope === 'full') {
            return $baseDn;
        }

        if ($scope === 'custom_dn') {
            return $this->value($batch, ['custom_source_dn', 'source_dns_text'], '');
        }

        if (in_array($scope, ['ou', 'cn', 'uid'], true)) {
            $attr = $this->value($batch, ['source_rdn_attribute'], $scope);
            $value = $this->value($batch, ['source_rdn_value'], '');

            if ($attr === '' || $value === '' || $baseDn === '') {
                return '';
            }

            if (str_contains($value, '=')) {
                return $value.','.$baseDn;
            }

            return $attr.'='.$value.','.$baseDn;
        }

        return $baseDn;
    }

    private function targetParentDn(LdapTransferBatch $batch): string
    {
        return $this->value($batch, ['target_parent_dn', 'target_dn', 'target_base_dn'], '');
    }

    private function targetDnFor(LdapTransferBatch $batch, string $sourceEntryDn, string $sourceDn, string $targetParentDn): string
    {
        $strategy = $this->value($batch, ['target_dn_strategy'], 'flatten');

        if ($strategy === 'flatten') {
            return $this->rdn($sourceEntryDn).','.$targetParentDn;
        }

        if ($strategy === 'replace_base') {
            $from = $this->value($batch, ['source_base_replacement'], $sourceDn);
            $to = $this->value($batch, ['target_base_replacement'], $targetParentDn);

            if ($from !== '' && $to !== '' && str_ends_with($sourceEntryDn, $from)) {
                return substr($sourceEntryDn, 0, -strlen($from)).$to;
            }
        }

        $suffix = ','.$sourceDn;

        if (str_ends_with($sourceEntryDn, $suffix)) {
            $relativeDn = substr($sourceEntryDn, 0, -strlen($suffix));
            return $relativeDn.','.$targetParentDn;
        }

        return $this->rdn($sourceEntryDn).','.$targetParentDn;
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ((bool) ($connection->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn)[0] ?? $dn;
    }

    private function firstValue(array $attributes, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            foreach ([$key, strtolower($key)] as $candidate) {
                if (! empty($attributes[$candidate][0])) {
                    return $this->escapeLdifValue((string) $attributes[$candidate][0]);
                }
            }
        }

        return $this->escapeLdifValue($default);
    }

    private function valuesFor(array $attributes, string $key): array
    {
        return $attributes[$key] ?? $attributes[strtolower($key)] ?? [];
    }

    private function value(LdapTransferBatch $batch, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $batch->{$key} ?? null;

            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return $default;
    }

    private function escapeLdifValue(string $value): string
    {
        return str_replace(["\r", "\n"], [' ', ' '], $value);
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
            DB::table($batch->getTable())->where('id', $batch->id)->update($clean);
        }
    }

    private function createOperationJob(LdapTransferBatch $batch, string $action, string $title): ?int
    {
        if (! Schema::hasTable('operation_jobs')) {
            return null;
        }

        $columns = Schema::getColumnListing('operation_jobs');

        $data = [
            'uuid' => (string) Str::uuid(),
            'name' => $title,
            'title' => $title,
            'type' => 'ldap_transfer',
            'operation_type' => 'ldap_transfer',
            'module' => 'operations.transfer',
            'action' => $action,
            'operation_action' => $action,
            'status' => 'running',
            'source' => 'filament',
            'target_type' => LdapTransferBatch::class,
            'target_key' => (string) $batch->id,
            'target_dn' => $this->effectiveSourceDn($batch),
            'ldap_connection_id' => $batch->source_ldap_connection_id,
            'created_by' => Auth::id(),
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'skipped_items' => 0,
            'progress' => 0,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insert = collect($data)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->toArray();

        return DB::table('operation_jobs')->insertGetId($insert);
    }

    private function createOperationLog(?int $operationJobId, string $level, string $message, array $context = []): void
    {
        if (! $operationJobId || ! Schema::hasTable('operation_job_logs')) {
            return;
        }

        $columns = Schema::getColumnListing('operation_job_logs');

        $data = [
            'uuid' => (string) Str::uuid(),
            'operation_job_id' => $operationJobId,
            'level' => $level,
            'event' => $context['event'] ?? 'ldap_transfer',
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insert = collect($data)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->toArray();

        DB::table('operation_job_logs')->insert($insert);
    }

    private function finishOperationJob(?int $operationJobId, string $status, int $total, int $success, int $failed, int $skipped, ?string $error): void
    {
        if (! $operationJobId || ! Schema::hasTable('operation_jobs')) {
            return;
        }

        $columns = Schema::getColumnListing('operation_jobs');

        $data = [
            'status' => $status,
            'total_items' => $total,
            'processed_items' => $success + $failed + $skipped,
            'success_items' => $success,
            'failed_items' => $failed,
            'skipped_items' => $skipped,
            'progress' => 100,
            'last_error' => $error,
            'error_message' => $error,
            'finished_at' => now(),
            'updated_at' => now(),
        ];

        $update = collect($data)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->toArray();

        DB::table('operation_jobs')->where('id', $operationJobId)->update($update);
    }
}
