<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportBatch;
use App\Models\Operations\ImportRow;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPreviewService
{
    public function preview(ImportBatch $batch): array
    {
        $startedAt = microtime(true);

        if (! $batch->hasUploadFile()) {
            return [
                'ok' => false,
                'message' => 'Import file is missing from storage.',
                'summary' => [],
            ];
        }

        $batch->forceFill([
            'status' => 'previewing',
            'preview_started_at' => now(),
            'message' => 'Import preview is running.',
        ])->save();

        try {
            $absolutePath = $batch->uploadedAbsolutePath();
            $fileHash = hash_file('sha256', $absolutePath);

            $parsedRows = match ($batch->import_type) {
                'csv' => $this->parseCsv($absolutePath),
                'json' => $this->parseJson($absolutePath),
                'ldif' => $this->parseLdif($absolutePath),
                default => throw new \RuntimeException('Unsupported import type: '.$batch->import_type),
            };

            $summary = [
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'duplicate_rows' => 0,
                'conflict_rows' => 0,
                'will_create_rows' => 0,
                'will_update_rows' => 0,
                'will_skip_rows' => 0,
                'will_fail_rows' => 0,
            ];

            DB::transaction(function () use ($batch, $parsedRows, &$summary): void {
                ImportRow::query()
                    ->where('import_batch_id', $batch->id)
                    ->delete();

                $seenIdentifiers = [];

                foreach ($parsedRows as $index => $rawPayload) {
                    $rowNumber = $index + 1;
                    $mapped = $this->mapPayload($batch, $rawPayload);
                    $validation = $this->validateMappedPayload($batch, $mapped);

                    $identifier = $mapped['identifier'] ?? null;
                    $targetDn = $mapped['target_dn'] ?? null;
                    $payloadHash = hash('sha256', json_encode($mapped, JSON_UNESCAPED_SLASHES));

                    $isDuplicate = false;

                    if (filled($identifier)) {
                        if (in_array($identifier, $seenIdentifiers, true)) {
                            $isDuplicate = true;
                        }

                        $seenIdentifiers[] = $identifier;
                    }

                    $status = 'valid';
                    $actionPlan = 'create';
                    $warnings = [];

                    if (! $validation['ok']) {
                        $status = 'invalid';
                        $actionPlan = 'fail';
                    } elseif ($isDuplicate) {
                        $status = 'duplicate';
                        $actionPlan = 'skip';
                        $warnings[] = 'Duplicate identifier inside uploaded file.';
                    }

                    ImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'row_number' => $rowNumber,
                        'status' => $status,
                        'action_plan' => $actionPlan,
                        'target_dn' => $targetDn,
                        'target_identifier' => $identifier,
                        'raw_payload' => $rawPayload,
                        'mapped_payload' => $mapped,
                        'validation_errors' => $validation['errors'],
                        'warnings' => $warnings,
                        'payload_hash' => $payloadHash,
                        'conflict_reason' => $isDuplicate ? 'Duplicate identifier inside uploaded file.' : null,
                        'message' => $validation['message'],
                    ]);

                    $summary['total_rows']++;

                    match ($status) {
                        'valid' => $summary['valid_rows']++,
                        'invalid' => $summary['invalid_rows']++,
                        'duplicate' => $summary['duplicate_rows']++,
                        'conflict' => $summary['conflict_rows']++,
                        default => $summary['will_skip_rows']++,
                    };

                    match ($actionPlan) {
                        'create' => $summary['will_create_rows']++,
                        'update' => $summary['will_update_rows']++,
                        'skip' => $summary['will_skip_rows']++,
                        'fail' => $summary['will_fail_rows']++,
                        default => $summary['will_skip_rows']++,
                    };
                }

                $batch->forceFill([
                    'status' => $summary['invalid_rows'] > 0 ? 'previewed_with_errors' : 'previewed',
                    'total_rows' => $summary['total_rows'],
                    'valid_rows' => $summary['valid_rows'],
                    'invalid_rows' => $summary['invalid_rows'],
                    'duplicate_rows' => $summary['duplicate_rows'],
                    'conflict_rows' => $summary['conflict_rows'],
                    'will_create_rows' => $summary['will_create_rows'],
                    'will_update_rows' => $summary['will_update_rows'],
                    'will_skip_rows' => $summary['will_skip_rows'],
                    'will_fail_rows' => $summary['will_fail_rows'],
                    'message' => 'Import preview completed.',
                    'preview_finished_at' => now(),
                ])->save();
            });

            $batch->forceFill([
                'file_size_bytes' => File::size($absolutePath),
                'file_hash' => $fileHash,
                'metadata' => array_merge($batch->metadata ?? [], [
                    'preview_duration_ms' => $this->durationMs($startedAt),
                    'parser' => $batch->import_type,
                    'safe_mode' => true,
                    'preview_only' => true,
                ]),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'preview_import',
                'status' => 'success',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'operation_job_id' => $batch->operation_job_id,
                'request_payload' => [
                    'name' => $batch->name,
                    'import_type' => $batch->import_type,
                    'file_path' => $batch->file_path,
                    'base_dn' => $batch->base_dn,
                    'identifier_attribute' => $batch->identifier_attribute,
                ],
                'after_value' => $summary,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [
                'ok' => true,
                'message' => 'Import preview completed.',
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'preview_finished_at' => now(),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'preview_import',
                'status' => 'failed',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'operation_job_id' => $batch->operation_job_id,
                'error_message' => $exception->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'summary' => [],
            ];
        }
    }

    private function parseCsv(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'r');

        if (! $handle) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers) || $headers === []) {
            fclose($handle);
            throw new \RuntimeException('CSV header row is missing.');
        }

        $headers = collect($headers)
            ->map(fn ($header): string => trim((string) $header))
            ->all();

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function parseJson(string $absolutePath): array
    {
        $content = File::get($absolutePath);
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('JSON file is invalid or not an array.');
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        if (isset($decoded['rows']) && is_array($decoded['rows'])) {
            return $decoded['rows'];
        }

        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            return $decoded['entries'];
        }

        return [$decoded];
    }

    private function parseLdif(string $absolutePath): array
    {
        $content = File::get($absolutePath);
        $blocks = preg_split("/\R{2,}/", trim($content)) ?: [];

        $rows = [];

        foreach ($blocks as $block) {
            $row = [];

            foreach (preg_split("/\R/", trim($block)) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);

                $key = trim($key);
                $value = trim($value);

                if (isset($row[$key])) {
                    if (! is_array($row[$key])) {
                        $row[$key] = [$row[$key]];
                    }

                    $row[$key][] = $value;
                } else {
                    $row[$key] = $value;
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function mapPayload(ImportBatch $batch, array $rawPayload): array
    {
        $identifierAttribute = $batch->identifier_attribute ?: 'uid';
        $identifier = $rawPayload[$identifierAttribute] ?? $rawPayload['uid'] ?? $rawPayload['cn'] ?? $rawPayload['mail'] ?? null;

        if (is_array($identifier)) {
            $identifier = $identifier[0] ?? null;
        }

        $identifier = trim((string) $identifier);

        $targetDn = $rawPayload['dn'] ?? null;

        if (is_array($targetDn)) {
            $targetDn = $targetDn[0] ?? null;
        }

        if (blank($targetDn) && filled($identifier) && filled($batch->base_dn)) {
            $targetDn = $identifierAttribute.'='.$identifier.','.$batch->base_dn;
        }

        return [
            'identifier_attribute' => $identifierAttribute,
            'identifier' => $identifier ?: null,
            'target_dn' => $targetDn ?: null,
            'object_classes' => $rawPayload['objectClass'] ?? $rawPayload['objectclass'] ?? [],
            'attributes' => $rawPayload,
        ];
    }

    private function validateMappedPayload(ImportBatch $batch, array $mapped): array
    {
        $errors = [];

        if (blank($mapped['identifier'])) {
            $errors[] = 'Missing identifier value.';
        }

        if (blank($mapped['target_dn'])) {
            $errors[] = 'Unable to determine target DN.';
        }

        if (filled($mapped['target_dn']) && ! str_contains((string) $mapped['target_dn'], '=')) {
            $errors[] = 'Target DN is not valid.';
        }

        if (filled($batch->base_dn) && filled($mapped['target_dn']) && ! str_ends_with(strtolower((string) $mapped['target_dn']), strtolower((string) $batch->base_dn))) {
            $errors[] = 'Target DN is outside configured Base DN.';
        }

        $warnings = [];

        $attributes = $mapped['attributes'] ?? [];
        $objectClasses = $mapped['object_classes'] ?? [];

        if (is_string($objectClasses)) {
            $objectClasses = [$objectClasses];
        }

        $hasInetOrgPerson = collect($objectClasses)
            ->contains(fn ($value): bool => strtolower((string) $value) === 'inetorgperson');

        if ($hasInetOrgPerson && blank($attributes['sn'] ?? null)) {
            $warnings[] = 'auto_sn_warning: inetOrgPerson requires sn. Apply LDIF generator will derive sn from cn if missing.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => $errors === [] ? 'Row is valid for preview.' : implode(' ', $errors),
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
