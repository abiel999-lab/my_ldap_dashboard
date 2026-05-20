<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobLog;
use App\Models\Operations\UniversalLdapTransferBatch;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class UniversalLdapTransferService
{
    public function preview(UniversalLdapTransferBatch $batch, OperationJob $operationJob): array
    {
        $source = $batch->sourceLdapConnection;
        $target = $batch->targetLdapConnection;

        if (! $source || ! $target) {
            return $this->fail('Source or target LDAP connection is missing.');
        }

        if (! $source->is_active || ! $target->is_active) {
            return $this->fail('Source and target LDAP connections must be active.');
        }

        if (blank($batch->effective_source_dn)) {
            return $this->fail('Effective source DN is empty.');
        }

        if (blank($batch->target_parent_dn)) {
            return $this->fail('Target parent DN is required.');
        }

        $this->log($operationJob, 'info', 'Starting LDAP transfer preview.', [
            'source_ldap_connection_id' => $source->id,
            'target_ldap_connection_id' => $target->id,
            'effective_source_dn' => $batch->effective_source_dn,
            'target_parent_dn' => $batch->target_parent_dn,
            'filter' => $batch->filter,
            'search_scope' => $batch->search_scope,
            'preview_only' => true,
        ]);

        $process = new Process($this->buildSearchCommand($source, $batch), base_path());
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            return $this->fail(trim($process->getErrorOutput()) ?: 'ldapsearch failed.');
        }

        $entries = $this->parseLdif($process->getOutput());

        if ($entries === []) {
            $content = $this->emptyPlan($batch);
        } else {
            $content = $this->buildTransferPlan($entries, $batch);
        }

        $path = $this->storePlan($batch, $content);

        $this->log($operationJob, 'info', 'LDAP transfer preview plan generated.', [
            'total_entries' => count($entries),
            'output_path' => $path,
            'preview_only' => true,
        ]);

        return [
            'ok' => true,
            'message' => 'LDAP transfer preview plan generated. Nothing was written to target LDAP.',
            'total_entries' => count($entries),
            'planned_entries' => count($entries),
            'transferred_entries' => 0,
            'failed_entries' => 0,
            'output_path' => $path,
            'output_size_bytes' => strlen($content),
            'output_hash' => hash('sha256', $content),
        ];
    }

    private function buildSearchCommand(LdapConnection $connection, UniversalLdapTransferBatch $batch): array
    {
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
            (string) $batch->effective_source_dn,
            '-s',
            (string) ($batch->search_scope ?: 'sub'),
            '-z',
            (string) ((int) ($batch->size_limit ?: 1000)),
            '-E',
            'pr='.(int) ($batch->page_size ?: 500).'/noprompt',
            (string) ($batch->filter ?: '(objectClass=*)'),
        ];

        foreach ($batch->attribute_list as $attribute) {
            $command[] = $attribute;
        }

        return $command;
    }

    private function buildTransferPlan(array $entries, UniversalLdapTransferBatch $batch): string
    {
        $lines = $this->header($batch);

        foreach ($entries as $entry) {
            $sourceDn = (string) $entry['dn'];
            $targetDn = $this->targetDnFor($sourceDn, (string) $batch->effective_source_dn, (string) $batch->target_parent_dn);

            $lines[] = '# ------------------------------------------------------------';
            $lines[] = '# Source DN: '.$sourceDn;
            $lines[] = '# Target DN: '.$targetDn;
            $lines[] = '# ------------------------------------------------------------';
            $lines[] = 'dn: '.$targetDn;
            $lines[] = 'changetype: add';

            foreach ($entry['attributes'] as $attribute => $values) {
                if ($this->isOperationalAttribute($attribute)) {
                    continue;
                }

                foreach ($values as $value) {
                    $lines[] = $attribute.': '.$value;
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function emptyPlan(UniversalLdapTransferBatch $batch): string
    {
        $lines = $this->header($batch);
        $lines[] = '# No LDAP entries matched the selected source DN/filter.';
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    private function header(UniversalLdapTransferBatch $batch): array
    {
        return [
            '# LDAP Transfer Preview Plan',
            '# Preview only. This file is NOT automatically applied.',
            '# Source LDAP: '.($batch->sourceLdapConnection?->name ?? 'N/A'),
            '# Target LDAP: '.($batch->targetLdapConnection?->name ?? 'N/A'),
            '# Source DN: '.$batch->effective_source_dn,
            '# Target Parent DN: '.$batch->target_parent_dn,
            '# Filter: '.$batch->filter,
            '# Search Scope: '.$batch->search_scope,
            '# Generated At: '.now()->toDateTimeString(),
            '',
        ];
    }

    private function parseLdif(string $ldif): array
    {
        $ldif = str_replace(["\r\n", "\r"], "\n", $ldif);
        $blocks = preg_split("/\n\s*\n/", trim($ldif));
        $entries = [];

        foreach ($blocks as $block) {
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

    private function targetDnFor(string $sourceDn, string $sourceBaseDn, string $targetParentDn): string
    {
        $sourceDn = trim($sourceDn);
        $sourceBaseDn = trim($sourceBaseDn);
        $targetParentDn = trim($targetParentDn);

        if ($sourceDn === $sourceBaseDn) {
            return $this->rdn($sourceDn).','.$targetParentDn;
        }

        $suffix = ','.$sourceBaseDn;

        if (str_ends_with($sourceDn, $suffix)) {
            $relativeDn = substr($sourceDn, 0, -strlen($suffix));

            return $relativeDn.','.$targetParentDn;
        }

        return $this->rdn($sourceDn).','.$targetParentDn;
    }

    private function storePlan(UniversalLdapTransferBatch $batch, string $content): string
    {
        $directory = 'ldap-transfer-plans/'.now()->format('Y/m/d');
        $filename = 'ldap-transfer-preview-'.$batch->id.'-'.now()->format('Ymd-His').'.ldif';
        $path = $directory.'/'.$filename;

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ((bool) ($connection->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn)[0] ?? $dn;
    }

    private function isOperationalAttribute(string $attribute): bool
    {
        return in_array(strtolower($attribute), [
            'entryuuid',
            'entrycsn',
            'createtimestamp',
            'creatorsname',
            'modifytimestamp',
            'modifiersname',
            'subschemasubentry',
            'hassubordinates',
            'structuralobjectclass',
        ], true);
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'total_entries' => 0,
            'planned_entries' => 0,
            'transferred_entries' => 0,
            'failed_entries' => 1,
        ];
    }

    private function log(OperationJob $job, string $level, string $message, array $context = []): void
    {
        if (! Schema::hasTable('operation_job_logs')) {
            return;
        }

        $data = [
            'uuid' => (string) Str::uuid(),
            'operation_job_id' => $job->id,
            'level' => $level,
            'event' => $context['event'] ?? 'universal_ldap_transfer',
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ];

        $columns = Schema::getColumnListing('operation_job_logs');

        OperationJobLog::query()->create(
            collect($data)
                ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
                ->toArray()
        );
    }
}
