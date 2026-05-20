<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapTransferBatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class LdapTransferPreviewService
{
    public function preview(LdapTransferBatch $batch): array
    {
        $source = $batch->sourceLdapConnection;
        $target = $batch->targetLdapConnection;

        if (! $source instanceof LdapConnection) {
            return $this->fail('Source LDAP connection not found.');
        }

        if (! $target instanceof LdapConnection) {
            return $this->fail('Target LDAP connection not found.');
        }

        $sourceDn = $this->effectiveSourceDn($batch);

        if ($sourceDn === '') {
            return $this->fail('Effective source DN is empty.');
        }

        $targetParentDn = $this->targetParentDn($batch);

        if ($targetParentDn === '') {
            return $this->fail('Target parent DN is empty.');
        }

        $filter = $this->value($batch, ['filter', 'ldap_filter'], '(objectClass=*)');
        $scope = $this->value($batch, ['search_scope', 'scope'], 'sub');
        $sizeLimit = (int) ($batch->size_limit ?: 1000);
        $pageSize = (int) ($batch->page_size ?: 500);
        $attributes = $this->attributeList($batch);

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

        foreach ($attributes as $attribute) {
            $command[] = $attribute;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(1800);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        if (! $process->isSuccessful()) {
            return $this->fail(trim($stderr) ?: 'ldapsearch failed.', $stdout, $stderr);
        }

        $entries = $this->parseLdif($stdout);
        $previewLdif = $this->buildPreviewLdif($batch, $entries, $sourceDn, $targetParentDn, $target);

        $path = 'ldap-transfer-previews/'.now()->format('Y/m/d').'/ldap-transfer-preview-'.$batch->id.'-'.now()->format('Ymd-His').'.ldif';

        Storage::disk('local')->put($path, $previewLdif);

        return [
            'ok' => true,
            'message' => 'LDAP transfer preview generated successfully. Nothing was written to target LDAP.',
            'stdout' => $stdout,
            'stderr' => $stderr,
            'preview_ldif' => $previewLdif,
            'output_path' => $path,
            'output_size_bytes' => strlen($previewLdif),
            'output_hash' => hash('sha256', $previewLdif),
            'total_entries' => count($entries),
            'success_entries' => count($entries),
            'planned_entries' => count($entries),
            'failed_entries' => 0,
            'skipped_entries' => 0,
        ];
    }

    private function buildPreviewLdif(
        LdapTransferBatch $batch,
        array $entries,
        string $sourceDn,
        string $targetParentDn,
        LdapConnection $target,
    ): string {
        $lines = [
            '# LDAP Transfer Preview LDIF',
            '# Preview only. This file is not applied automatically.',
            '# Target LDAP: '.$target->name,
            '# Source DN: '.$sourceDn,
            '# Target Parent DN: '.$targetParentDn,
            '# Strategy: '.$this->value($batch, ['target_dn_strategy'], 'flatten'),
            '# Generated At: '.now()->toDateTimeString(),
            '',
        ];

        if ($entries === []) {
            $lines[] = '# No entries matched selected source/filter.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($entries as $entry) {
            $sourceEntryDn = $entry['dn'];
            $targetDn = $this->targetDnFor($batch, $sourceEntryDn, $sourceDn, $targetParentDn);

            $lines[] = '# ------------------------------------------------------------';
            $lines[] = '# Source DN: '.$sourceEntryDn;
            $lines[] = '# Target DN: '.$targetDn;
            $lines[] = '# ------------------------------------------------------------';
            $lines[] = 'dn: '.$targetDn;
            $lines[] = 'changetype: add';

            foreach ($entry['attributes'] as $attribute => $values) {
                if ($this->shouldSkipAttribute($batch, $attribute)) {
                    continue;
                }

                foreach ($values as $value) {
                    $lines[] = $attribute.': '.$value;
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
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

        if ($sourceEntryDn === $sourceDn) {
            return $this->rdn($sourceEntryDn).','.$targetParentDn;
        }

        $suffix = ','.$sourceDn;

        if (str_ends_with($sourceEntryDn, $suffix)) {
            $relativeDn = substr($sourceEntryDn, 0, -strlen($suffix));

            return $relativeDn.','.$targetParentDn;
        }

        return $this->rdn($sourceEntryDn).','.$targetParentDn;
    }

    private function shouldSkipAttribute(LdapTransferBatch $batch, string $attribute): bool
    {
        $attribute = strtolower($attribute);

        $excluded = $batch->excluded_attributes ?? [];

        if (is_string($excluded)) {
            $excluded = preg_split('/[\s,]+/', $excluded) ?: [];
        }

        $excluded = collect($excluded)
            ->map(fn ($item): string => strtolower(trim((string) $item)))
            ->filter()
            ->values()
            ->all();

        if (in_array($attribute, $excluded, true)) {
            return true;
        }

        if (! (bool) ($batch->include_operational_attributes ?? false)) {
            return in_array($attribute, [
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

        return false;
    }

    private function attributeList(LdapTransferBatch $batch): array
    {
        $attributes = $this->value($batch, ['attributes'], '*');

        if ($attributes === '') {
            return ['*'];
        }

        return collect(preg_split('/[\s,]+/', $attributes) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all() ?: ['*'];
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ((bool) ($connection->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn)[0] ?? $dn;
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

    private function fail(string $message, string $stdout = '', string $stderr = ''): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'total_entries' => 0,
            'success_entries' => 0,
            'planned_entries' => 0,
            'failed_entries' => 1,
            'skipped_entries' => 0,
        ];
    }
}
