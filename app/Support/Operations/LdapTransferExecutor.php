<?php

namespace App\Support\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use App\Models\Operations\LdapTransferItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class LdapTransferExecutor
{
    public function preview(LdapTransferBatch $batch): array
    {
        $source = LdapConnection::query()->findOrFail($batch->source_ldap_connection_id);

        $sourceDns = $this->resolveSourceDns($batch, $source);

        $batch->items()->delete();

        $previewLdif = [];

        foreach ($sourceDns as $sourceDn) {
            $targetDn = $this->resolveTargetDn($batch, $sourceDn, count($sourceDns));

            $entry = $this->ldapSearchBaseEntry(
                $source,
                $sourceDn,
                (bool) $batch->include_operational_attributes
            );

            $ldif = ($entry['exit_code'] ?? 1) === 0
                ? $this->transformEntryForAdd(
                    $entry['stdout'] ?? '',
                    $sourceDn,
                    $targetDn,
                    $batch->excluded_attributes ?: []
                )
                : '';

            LdapTransferItem::query()->create([
                'ldap_transfer_batch_id' => $batch->id,
                'source_dn' => $sourceDn,
                'target_dn' => $targetDn,
                'status' => 'pending',
                'operation' => 'preview',
                'ldif' => $ldif,
                'stdout' => $entry['stdout'] ?? null,
                'stderr' => $entry['stderr'] ?? null,
                'error_message' => ($entry['exit_code'] ?? 1) === 0 ? null : ($entry['stderr'] ?? 'Source entry read failed.'),
            ]);

            if ($ldif !== '') {
                $previewLdif[] = $ldif;
            }
        }

        $batch->update([
            'preview_ldif' => implode("\n", $previewLdif),
            'stdout' => 'Preview generated for '.count($sourceDns).' source DN(s).',
            'stderr' => null,
            'total_entries' => count($sourceDns),
            'success_entries' => 0,
            'failed_entries' => 0,
            'skipped_entries' => 0,
            'status' => 'success',
            'finished_at' => now(),
        ]);

        return [
            'ok' => true,
            'operation' => 'preview',
            'source_input_mode' => $batch->source_input_mode,
            'total_entries' => count($sourceDns),
            'stdout' => 'Preview generated for '.count($sourceDns).' source DN(s).',
            'stderr' => '',
            'exit_code' => 0,
        ];
    }

    public function execute(LdapTransferBatch $batch): array
    {
        $source = LdapConnection::query()->findOrFail($batch->source_ldap_connection_id);
        $target = LdapConnection::query()->findOrFail($batch->target_ldap_connection_id);

        $batch->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'success_entries' => 0,
            'failed_entries' => 0,
            'skipped_entries' => 0,
        ]);

        if ($batch->items()->count() === 0) {
            $this->preview($batch);
            $batch->refresh();

            $batch->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
            ]);
        }

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $stdoutAll = [];
        $stderrAll = [];

        foreach ($batch->items()->orderBy('id')->get() as $item) {
            try {
                $sourceDn = (string) $item->source_dn;
                $targetDn = (string) $item->target_dn;

                if ($sourceDn === '' || $targetDn === '') {
                    throw new \RuntimeException('Source DN or Target DN is empty.');
                }

                $exists = $this->targetExists($target, $targetDn);

                if ($exists && $batch->collision_strategy === 'skip') {
                    $item->update([
                        'status' => 'skipped',
                        'operation' => $batch->mode,
                        'error_message' => 'Target DN already exists. Skipped by collision_strategy=skip.',
                    ]);

                    $skipped++;
                    continue;
                }

                if ($exists && $batch->collision_strategy === 'fail') {
                    throw new \RuntimeException('Target DN already exists: '.$targetDn);
                }

                if ($exists && $batch->collision_strategy === 'replace') {
                    $deleteResult = $this->ldapDelete($target, $targetDn);

                    $stdoutAll[] = $deleteResult['stdout'] ?? '';
                    $stderrAll[] = $deleteResult['stderr'] ?? '';

                    if (($deleteResult['exit_code'] ?? 1) !== 0) {
                        throw new \RuntimeException(trim($deleteResult['stderr'] ?? '') ?: 'Failed to delete existing target before replace.');
                    }
                }

                $entry = $this->ldapSearchBaseEntry(
                    $source,
                    $sourceDn,
                    (bool) $batch->include_operational_attributes
                );

                if (($entry['exit_code'] ?? 1) !== 0) {
                    throw new \RuntimeException(trim($entry['stderr'] ?? '') ?: 'Failed to read source entry.');
                }

                $ldif = $this->transformEntryForAdd(
                    $entry['stdout'] ?? '',
                    $sourceDn,
                    $targetDn,
                    $batch->excluded_attributes ?: []
                );

                $addResult = $this->ldapAdd($target, $ldif);

                $stdoutAll[] = $addResult['stdout'] ?? '';
                $stderrAll[] = $addResult['stderr'] ?? '';

                if (($addResult['exit_code'] ?? 1) !== 0) {
                    throw new \RuntimeException(trim($addResult['stderr'] ?? '') ?: 'ldapadd failed.');
                }

                if ($batch->mode === 'move' || $batch->delete_source_after_copy) {
                    $deleteSource = $this->ldapDelete($source, $sourceDn);

                    $stdoutAll[] = $deleteSource['stdout'] ?? '';
                    $stderrAll[] = $deleteSource['stderr'] ?? '';

                    if (($deleteSource['exit_code'] ?? 1) !== 0) {
                        throw new \RuntimeException(trim($deleteSource['stderr'] ?? '') ?: 'Target copied, but failed to delete source.');
                    }
                }

                $item->update([
                    'status' => 'success',
                    'operation' => $batch->mode,
                    'ldif' => $ldif,
                    'stdout' => implode("\n", array_filter($stdoutAll)),
                    'stderr' => implode("\n", array_filter($stderrAll)),
                    'error_message' => null,
                ]);

                $success++;
            } catch (Throwable $e) {
                $item->update([
                    'status' => 'failed',
                    'operation' => $batch->mode,
                    'stderr' => implode("\n", array_filter($stderrAll)),
                    'error_message' => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        $status = $failed > 0 ? 'failed' : 'success';

        $batch->update([
            'status' => $status,
            'success_entries' => $success,
            'failed_entries' => $failed,
            'skipped_entries' => $skipped,
            'stdout' => implode("\n", array_filter($stdoutAll)),
            'stderr' => implode("\n", array_filter($stderrAll)),
            'finished_at' => now(),
        ]);

        return [
            'ok' => $failed === 0,
            'operation' => $batch->mode,
            'batch_id' => $batch->id,
            'source_input_mode' => $batch->source_input_mode,
            'total_entries' => $batch->total_entries,
            'success_entries' => $success,
            'failed_entries' => $failed,
            'skipped_entries' => $skipped,
            'stdout' => implode("\n", array_filter($stdoutAll)),
            'stderr' => implode("\n", array_filter($stderrAll)),
            'exit_code' => $failed === 0 ? 0 : 1,
        ];
    }

    private function resolveSourceDns(LdapTransferBatch $batch, LdapConnection $source): array
    {
        $mode = (string) ($batch->source_input_mode ?: 'filter');

        if ($mode === 'dn_list') {
            $dns = $this->parseDnsFromText((string) $batch->source_dns_text);

            $fileDns = $this->parseDnsFromFile((string) $batch->source_file_path);

            $merged = array_values(array_unique(array_filter(array_merge($dns, $fileDns))));

            if ($merged === []) {
                throw new \RuntimeException('No source DN found. Fill Source DN List or upload CSV/TXT file.');
            }

            return $merged;
        }

        $baseDn = trim((string) $batch->source_base_dn);
        $filter = trim((string) $batch->ldap_filter) ?: '(objectClass=*)';

        if ($baseDn === '') {
            throw new \RuntimeException('Source Base DN is required for OU/filter mode.');
        }

        $result = $this->ldapSearch(
            $source,
            $baseDn,
            $filter,
            (string) $batch->scope,
            false
        );

        if (($result['exit_code'] ?? 1) !== 0) {
            throw new \RuntimeException(trim($result['stderr'] ?? '') ?: 'LDAP search failed.');
        }

        $entries = $this->splitEntries($result['stdout'] ?? '');

        $dns = [];

        foreach ($entries as $entry) {
            $dn = $this->extractDn($entry);

            if ($dn !== '') {
                $dns[] = $dn;
            }
        }

        if ($dns === []) {
            throw new \RuntimeException('LDAP filter returned zero entries.');
        }

        return array_values(array_unique($dns));
    }

    private function parseDnsFromText(string $text): array
    {
        $dns = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, ',')) {
                $csv = str_getcsv($line);
                $candidate = trim((string) ($csv[0] ?? ''));
            } else {
                $candidate = $line;
            }

            if ($candidate !== '' && str_contains($candidate, '=')) {
                $dns[] = $candidate;
            }
        }

        return $dns;
    }

    private function parseDnsFromFile(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            return [];
        }

        $possiblePaths = [
            $path,
            storage_path('app/'.$path),
            storage_path('app/public/'.$path),
            storage_path('app/private/'.$path),
        ];

        $realPath = null;

        foreach ($possiblePaths as $candidate) {
            if (is_file($candidate)) {
                $realPath = $candidate;
                break;
            }
        }

        if (! $realPath) {
            return [];
        }

        return $this->parseDnsFromText((string) file_get_contents($realPath));
    }

    private function resolveTargetDn(LdapTransferBatch $batch, string $sourceDn, int $sourceCount): string
    {
        $targetDn = trim((string) ($batch->target_dn ?: $batch->target_base_dn));

        if ($targetDn === '') {
            throw new \RuntimeException('Target DN is required.');
        }

        $mode = (string) ($batch->target_dn_mode ?: 'auto');

        if ($mode === 'exact') {
            if ($sourceCount > 1) {
                throw new \RuntimeException('Target DN mode exact cannot be used with multiple source DNs.');
            }

            return $targetDn;
        }

        if ($mode === 'parent') {
            return $this->sourceRdn($sourceDn).','.$targetDn;
        }

        if ((string) $batch->source_input_mode === 'filter') {
            $sourceBaseDn = trim((string) $batch->source_base_dn);

            if ($sourceBaseDn !== '' && $this->dnEndsWith($sourceDn, $sourceBaseDn)) {
                return $this->mapDn($sourceDn, $sourceBaseDn, $targetDn);
            }
        }

        if ($sourceCount === 1 && $this->looksLikeFullEntryDn($targetDn)) {
            return $targetDn;
        }

        return $this->sourceRdn($sourceDn).','.$targetDn;
    }

    private function ldapSearch(LdapConnection $connection, string $baseDn, string $filter, string $scope, bool $includeOperational): array
    {
        $scope = match ($scope) {
            'base' => 'base',
            'one' => 'one',
            default => 'sub',
        };

        $command = array_merge($this->baseLdapCommand('ldapsearch', $connection), [
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-b',
            $baseDn,
            '-s',
            $scope,
            $filter,
            '*',
        ]);

        if ($includeOperational) {
            $command[] = '+';
        }

        return $this->run($command, 300);
    }

    private function ldapSearchBaseEntry(LdapConnection $connection, string $dn, bool $includeOperational): array
    {
        $command = array_merge($this->baseLdapCommand('ldapsearch', $connection), [
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-b',
            $dn,
            '-s',
            'base',
            '(objectClass=*)',
            '*',
        ]);

        if ($includeOperational) {
            $command[] = '+';
        }

        return $this->run($command, 120);
    }

    private function ldapAdd(LdapConnection $connection, string $ldif): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ldap_transfer_add_');
        file_put_contents($tmp, $ldif);

        $command = array_merge($this->baseLdapCommand('ldapadd', $connection), [
            '-f',
            $tmp,
        ]);

        $result = $this->run($command, 180);

        @unlink($tmp);

        return $result;
    }

    private function ldapDelete(LdapConnection $connection, string $dn): array
    {
        $command = array_merge($this->baseLdapCommand('ldapdelete', $connection), [
            '-r',
            $dn,
        ]);

        return $this->run($command, 180);
    }

    private function targetExists(LdapConnection $connection, string $dn): bool
    {
        $result = $this->ldapSearchBaseEntry($connection, $dn, false);

        return ($result['exit_code'] ?? 1) === 0 && str_contains($result['stdout'] ?? '', 'dn:');
    }

    private function baseLdapCommand(string $binary, LdapConnection $connection): array
    {
        $command = [
            $binary,
            '-x',
            '-H',
            $this->ldapUri($connection),
        ];

        $bindDn = $this->value($connection, ['bind_dn', 'username']);
        $password = $this->passwordValue($connection);

        if ($bindDn !== '') {
            $command[] = '-D';
            $command[] = $bindDn;
        }

        if ($password !== '') {
            $command[] = '-w';
            $command[] = $password;
        }

        return $command;
    }

    private function passwordValue(LdapConnection $connection): string
    {
        try {
            return (string) ($connection->bind_password ?? $connection->password ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if ($host !== '' && str_contains($host, '://')) {
            return $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function run(array $command, int $timeout): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'command' => $this->redactedCommand($command),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? 0,
        ];
    }

    private function splitEntries(string $ldif): array
    {
        $ldif = trim($this->unfoldLdif($ldif));

        if ($ldif === '') {
            return [];
        }

        $chunks = preg_split("/\n\s*\n/", $ldif) ?: [];

        return array_values(array_filter(array_map('trim', $chunks), fn ($entry): bool => str_starts_with($entry, 'dn:')));
    }

    private function extractDn(string $entry): string
    {
        foreach (preg_split('/\r?\n/', $entry) as $line) {
            if (str_starts_with($line, 'dn:')) {
                return trim(substr($line, 3));
            }
        }

        return '';
    }

    private function sourceRdn(string $dn): string
    {
        return trim(explode(',', $dn, 2)[0] ?? $dn);
    }

    private function looksLikeFullEntryDn(string $dn): bool
    {
        $parts = array_map('trim', explode(',', $dn));

        if (count($parts) < 2) {
            return false;
        }

        $first = strtolower($parts[0]);

        return str_starts_with($first, 'uid=')
            || str_starts_with($first, 'cn=')
            || str_starts_with($first, 'ou=')
            || str_starts_with($first, 'dc=');
    }

    private function dnEndsWith(string $dn, string $suffix): bool
    {
        return str_ends_with(strtolower($dn), strtolower($suffix));
    }

    private function mapDn(string $sourceDn, string $sourceBaseDn, string $targetBaseDn): string
    {
        if ($sourceBaseDn !== '' && $this->dnEndsWith($sourceDn, $sourceBaseDn)) {
            $prefix = substr($sourceDn, 0, strlen($sourceDn) - strlen($sourceBaseDn));
            $prefix = rtrim($prefix, ',');

            return $prefix !== ''
                ? $prefix.','.$targetBaseDn
                : $targetBaseDn;
        }

        return $this->sourceRdn($sourceDn).','.$targetBaseDn;
    }

    private function transformEntryForAdd(string $entry, string $sourceDn, string $targetDn, array $excludedAttributes): string
    {
        $entry = $this->unfoldLdif($entry);

        $excluded = array_map('strtolower', array_merge([
            'entryuuid',
            'entrycsn',
            'createtimestamp',
            'modifytimestamp',
            'creatorsname',
            'modifiersname',
            'hassubordinates',
            'subschemasubentry',
            'structuralobjectclass',
            'contextcsn',
        ], $excludedAttributes));

        $lines = preg_split('/\r?\n/', trim($entry)) ?: [];
        $targetRdn = $this->parseRdn($targetDn);

        $result = [
            'dn: '.$targetDn,
        ];

        $hasTargetRdnAttribute = false;

        foreach ($lines as $line) {
            $line = rtrim((string) $line);

            if ($line === '' || str_starts_with($line, 'dn:')) {
                continue;
            }

            $attribute = strtolower(trim(explode(':', $line, 2)[0] ?? ''));

            if ($attribute === '' || in_array($attribute, $excluded, true)) {
                continue;
            }

            if ($targetRdn && strtolower($targetRdn['attribute']) === $attribute) {
                $hasTargetRdnAttribute = true;

                $result[] = $targetRdn['attribute'].': '.$targetRdn['value'];
                continue;
            }

            $result[] = $line;
        }

        if ($targetRdn && ! $hasTargetRdnAttribute) {
            $result[] = $targetRdn['attribute'].': '.$targetRdn['value'];
        }

        return implode("\n", array_values(array_unique($result)))."\n";
    }

    private function parseRdn(string $dn): ?array
    {
        $rdn = $this->sourceRdn($dn);

        if (! str_contains($rdn, '=')) {
            return null;
        }

        [$attribute, $value] = explode('=', $rdn, 2);

        return [
            'attribute' => trim($attribute),
            'value' => trim($value),
        ];
    }

    private function unfoldLdif(string $ldif): string
    {
        $lines = preg_split('/\r?\n/', $ldif) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') && $result !== []) {
                $result[count($result) - 1] .= substr($line, 1);
                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function value($model, array $columns, string $default = ''): string
    {
        foreach ($columns as $column) {
            if (isset($model->{$column}) && $model->{$column} !== null && $model->{$column} !== '') {
                return (string) $model->{$column};
            }
        }

        return $default;
    }

    private function redactedCommand(array $command): string
    {
        $copy = $command;

        foreach ($copy as $i => $part) {
            if ($part === '-w' && isset($copy[$i + 1])) {
                $copy[$i + 1] = '[REDACTED]';
            }
        }

        return implode(' ', array_map('escapeshellarg', $copy));
    }
}
