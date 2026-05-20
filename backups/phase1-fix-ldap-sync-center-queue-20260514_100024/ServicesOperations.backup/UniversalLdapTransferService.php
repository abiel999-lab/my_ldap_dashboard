<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobLog;
use App\Models\Operations\UniversalLdapTransferBatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class UniversalLdapTransferService
{
    public function transfer(UniversalLdapTransferBatch $batch, OperationJob $operationJob): array
    {
        $source = $batch->sourceLdapConnection;
        $target = $batch->targetLdapConnection;

        if (! $source || ! $target) {
            return ['ok' => false, 'message' => 'Source or target LDAP connection is missing.'];
        }

        if (! $source->is_active || ! $target->is_active) {
            return ['ok' => false, 'message' => 'Source and target LDAP connections must be active.'];
        }

        if (blank($batch->effective_source_dn)) {
            return ['ok' => false, 'message' => 'Effective source DN is empty.'];
        }

        if (blank($batch->target_parent_dn)) {
            return ['ok' => false, 'message' => 'Target parent DN is required.'];
        }

        $this->log($operationJob, 'info', 'Starting LDAP transfer preview.', [
            'source_ldap_connection_id' => $source->id,
            'target_ldap_connection_id' => $target->id,
            'effective_source_dn' => $batch->effective_source_dn,
            'target_parent_dn' => $batch->target_parent_dn,
            'preview_only' => true,
        ]);

        $process = new Process($this->buildSearchCommand($source, $batch), base_path());
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'message' => $process->getErrorOutput() ?: 'ldapsearch failed.',
            ];
        }

        $entries = $this->parseLdif($process->getOutput());
        $plannedLdif = $this->buildTransferPlan($entries, $batch);

        $path = $this->storePlan($batch, $plannedLdif);

        $this->log($operationJob, 'info', 'LDAP transfer plan generated.', [
            'total_entries' => count($entries),
            'output_path' => $path,
            'preview_only' => true,
        ]);

        return [
            'ok' => true,
            'message' => 'LDAP transfer preview plan generated. Apply mode is intentionally disabled for safety.',
            'total_entries' => count($entries),
            'planned_entries' => count($entries),
            'transferred_entries' => 0,
            'failed_entries' => 0,
            'output_path' => $path,
            'output_size_bytes' => strlen($plannedLdif),
            'output_hash' => hash('sha256', $plannedLdif),
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
            'pr='.$batch->page_size.'/noprompt',
            (string) $batch->filter,
        ];

        foreach ($batch->attribute_list as $attribute) {
            $command[] = $attribute;
        }

        return $command;
    }

    private function buildTransferPlan(array $entries, UniversalLdapTransferBatch $batch): string
    {
        $lines = [];
        $lines[] = '# LDAP Transfer Preview Plan';
        $lines[] = '# Source DN: '.$batch->effective_source_dn;
        $lines[] = '# Target Parent DN: '.$batch->target_parent_dn;
        $lines[] = '# Preview Only: true';
        $lines[] = '# Generated At: '.now()->toDateTimeString();
        $lines[] = '';

        foreach ($entries as $entry) {
            $sourceDn = $entry['dn'];
            $sourceRdn = $this->rdn($sourceDn);
            $targetDn = $sourceRdn.','.$batch->target_parent_dn;

            $lines[] = '# Source DN: '.$sourceDn;
            $lines[] = 'dn: '.$targetDn;
            $lines[] = 'changetype: add';

            foreach ($entry['attributes'] as $attribute => $values) {
                if (in_array(strtolower($attribute), [
                    'entryuuid',
                    'createtimestamp',
                    'creatorsname',
                    'modifiersname',
                    'modifytimestamp',
                    'entrycsn',
                    'subschemasubentry',
                    'hassubordinates',
                    'structuralobjectclass',
                ], true)) {
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

    private function parseLdif(string $ldif): array
    {
        $ldif = str_replace(["\r\n", "\r"], "\n", $ldif);
        $blocks = preg_split("/\n\s*\n/", trim($ldif));
        $entries = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            $dn = null;
            $attributes = [];

            foreach ($lines as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim(ltrim($value));

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

    private function storePlan(UniversalLdapTransferBatch $batch, string $content): string
    {
        $directory = 'ldap-transfer-plans/'.now()->format('Y/m/d');
        $filename = 'ldap-transfer-'.$batch->id.'-'.now()->format('Ymd-His').'.ldif';
        $path = $directory.'/'.$filename;

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ($connection->use_ssl ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn)[0] ?? $dn;
    }

    private function log(OperationJob $job, string $level, string $message, array $context = []): void
    {
        OperationJobLog::query()->create([
            'operation_job_id' => $job->id,
            'level' => $level,
            'event' => 'universal_ldap_transfer',
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
