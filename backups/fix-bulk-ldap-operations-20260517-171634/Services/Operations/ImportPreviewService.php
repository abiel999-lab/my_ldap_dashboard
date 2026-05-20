<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportBatch;
use App\Models\Operations\ImportRow;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPreviewService
{
    public function preview(ImportBatch $batch): array
    {
        $startedAt = microtime(true);

        $batch->loadMissing('ldapConnection');

        $absolutePath = $this->resolveAbsolutePath($batch);

        if (! $absolutePath || ! is_file($absolutePath)) {
            $message = 'Import file not found: '.($batch->file_path ?: 'N/A');

            $batch->forceFill([
                'status' => 'preview_failed',
                'message' => $message,
                'preview_started_at' => now(),
                'preview_finished_at' => now(),
            ])->save();

            $this->auditPreview($batch, 'failed', $message, $startedAt);

            return [
                'ok' => false,
                'message' => $message,
            ];
        }

        try {
            $batch->forceFill([
                'status' => 'previewing',
                'preview_started_at' => now(),
                'message' => 'Import preview is running.',
            ])->save();

            $rawRows = match ($batch->import_type) {
                'csv' => $this->parseCsv($absolutePath),
                'json' => $this->parseJson($absolutePath),
                'ldif' => $this->parseLdif($absolutePath),
                default => throw new \InvalidArgumentException('Unsupported import type: '.$batch->import_type),
            };

            $seenIdentifiers = [];
            $counters = [
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

            DB::transaction(function () use ($batch, $rawRows, &$seenIdentifiers, &$counters): void {
                $batch->rows()->delete();

                foreach ($rawRows as $index => $rawPayload) {
                    $rowNumber = $index + 1;
                    $mapped = $this->mapPayload($batch, $rawPayload);
                    $validationErrors = $this->validateMappedPayload($batch, $mapped);
                    $warnings = [];
                    $status = 'valid';
                    $actionPlan = $this->determineRequestedAction($rawPayload, $mapped);
                    $conflictReason = null;

                    $identifier = $mapped['identifier'] ?? null;
                    $targetDn = $mapped['target_dn'] ?? null;
                    $payloadHash = hash('sha256', json_encode($mapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                    if ($identifier && in_array($identifier, $seenIdentifiers, true)) {
                        $status = 'duplicate';
                        $actionPlan = 'skip';
                        $warnings[] = 'Duplicate identifier inside uploaded file.';
                        $conflictReason = 'Duplicate identifier inside uploaded file.';
                    } elseif ($identifier) {
                        $seenIdentifiers[] = $identifier;
                    }

                    if ($validationErrors !== []) {
                        $status = 'invalid';
                        $actionPlan = 'fail';
                    }

                    if ($status === 'valid') {
                        $dnExists = app(ImportLdapExistenceService::class)->dnExists($batch->ldapConnection, $targetDn);

                        if ($actionPlan === 'create') {
                            if ($dnExists) {
                                $status = 'skipped';
                                $actionPlan = 'skip';
                                $warnings[] = 'Target DN already exists in LDAP. Create operation skipped safely.';
                                $conflictReason = 'Target DN already exists in LDAP.';
                            }
                        } elseif (in_array($actionPlan, ['update', 'modify'], true)) {
                            if (! $dnExists) {
                                $status = 'invalid';
                                $actionPlan = 'fail';
                                $validationErrors[] = 'Target DN does not exist in LDAP. Update/modify cannot be applied.';
                            }
                        } elseif ($actionPlan === 'delete') {
                            if (! $dnExists) {
                                $status = 'skipped';
                                $actionPlan = 'skip';
                                $warnings[] = 'Target DN does not exist in LDAP. Delete already applied safely.';
                                $conflictReason = 'Target DN does not exist in LDAP.';
                            }
                        }
                    }

                    $message = $this->rowMessage($status, $validationErrors, $warnings, $actionPlan);

                    ImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'row_number' => $rowNumber,
                        'status' => $status,
                        'action_plan' => $actionPlan,
                        'target_dn' => $targetDn,
                        'target_identifier' => $identifier,
                        'raw_payload' => $rawPayload,
                        'mapped_payload' => $mapped,
                        'validation_errors' => $validationErrors,
                        'warnings' => $warnings,
                        'payload_hash' => $payloadHash,
                        'conflict_reason' => $conflictReason,
                        'message' => $message,
                    ]);

                    $counters['total_rows']++;

                    match ($status) {
                        'valid' => $counters['valid_rows']++,
                        'invalid' => $counters['invalid_rows']++,
                        'duplicate' => $counters['duplicate_rows']++,
                        'skipped' => $counters['will_skip_rows']++,
                        default => null,
                    };

                    match ($actionPlan) {
                        'create' => $counters['will_create_rows']++,
                        'update', 'modify' => $counters['will_update_rows']++,
                        'skip' => $counters['will_skip_rows']++,
                        'fail' => $counters['will_fail_rows']++,
                        default => null,
                    };
                }

                $status = $counters['invalid_rows'] > 0 || $counters['will_fail_rows'] > 0
                    ? 'previewed_with_errors'
                    : 'previewed';

                $batch->forceFill([
                    'status' => $status,
                    'total_rows' => $counters['total_rows'],
                    'valid_rows' => $counters['valid_rows'],
                    'invalid_rows' => $counters['invalid_rows'],
                    'duplicate_rows' => $counters['duplicate_rows'],
                    'conflict_rows' => $counters['conflict_rows'],
                    'will_create_rows' => $counters['will_create_rows'],
                    'will_update_rows' => $counters['will_update_rows'],
                    'will_skip_rows' => $counters['will_skip_rows'],
                    'will_fail_rows' => $counters['will_fail_rows'],
                    'safe_mode' => true,
                    'preview_only' => true,
                    'destructive' => false,
                    'preview_finished_at' => now(),
                    'message' => 'Import preview completed.',
                    'metadata' => array_merge($batch->metadata ?? [], [
                        'preview_duration_ms' => $this->durationMs($GLOBALS['startedAt'] ?? microtime(true)),
                        'parser' => $batch->import_type,
                        'safe_mode' => true,
                        'preview_only' => true,
                        'existing_dn_check' => (bool) $batch->ldap_connection_id,
                        'all_ldap_preview_mode' => ! (bool) $batch->ldap_connection_id,
                        'skip_existing_create' => true,
                    ]),
                ])->save();
            });

            $batch->refresh();

            $this->auditPreview($batch, $batch->status === 'previewed' ? 'success' : 'warning', $batch->message, $startedAt);

            return [
                'ok' => true,
                'message' => $batch->message,
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'will_create_rows' => $batch->will_create_rows,
                'will_update_rows' => $batch->will_update_rows,
                'will_skip_rows' => $batch->will_skip_rows,
                'will_fail_rows' => $batch->will_fail_rows,
            ];
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'preview_failed',
                'message' => 'Import preview failed.',
                'preview_finished_at' => now(),
                'metadata' => array_merge($batch->metadata ?? [], [
                    'preview_error' => $exception->getMessage(),
                ]),
            ])->save();

            $this->auditPreview($batch, 'failed', $exception->getMessage(), $startedAt);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function resolveAbsolutePath(ImportBatch $batch): ?string
    {
        $path = trim((string) $batch->file_path);

        if ($path === '') {
            return null;
        }

        $candidates = [
            storage_path('app/private/'.$path),
            storage_path('app/'.$path),
            Storage::disk($batch->file_disk ?: 'local')->path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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

            return [];
        }

        $headers = array_map(fn ($header): string => trim((string) $header), $headers);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
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
        $decoded = json_decode((string) file_get_contents($absolutePath), true);

        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return [$decoded];
    }

    private function parseLdif(string $absolutePath): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($absolutePath));
        $blocks = preg_split("/\n\s*\n/", trim($content)) ?: [];
        $rows = [];

        foreach ($blocks as $block) {
            $payload = [];
            $lastKey = null;

            foreach (explode("\n", trim($block)) as $line) {
                if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                    continue;
                }

                if (str_starts_with($line, ' ') && $lastKey) {
                    $payload[$lastKey] = ($payload[$lastKey] ?? '').substr($line, 1);

                    continue;
                }

                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = ltrim($value);

                if (array_key_exists($key, $payload)) {
                    if (! is_array($payload[$key])) {
                        $payload[$key] = [$payload[$key]];
                    }

                    $payload[$key][] = $value;
                } else {
                    $payload[$key] = $value;
                }

                $lastKey = $key;
            }

            if ($payload !== []) {
                $rows[] = $payload;
            }
        }

        return $rows;
    }

    private function mapPayload(ImportBatch $batch, array $rawPayload): array
    {
        $identifierAttribute = $batch->identifier_attribute ?: 'uid';
        $targetDn = trim((string) ($rawPayload['dn'] ?? $rawPayload['DN'] ?? ''));

        $identifier = $rawPayload[$identifierAttribute] ?? null;

        if (is_array($identifier)) {
            $identifier = $identifier[0] ?? null;
        }

        if (! $identifier && $targetDn !== '') {
            $identifier = $this->extractRdnValue($targetDn);
        }

        $ou = trim((string) ($rawPayload['ou'] ?? $rawPayload['target_ou'] ?? ''));
        $baseDn = trim((string) $batch->base_dn);

        if ($targetDn === '' && $identifier) {
            if ($ou !== '') {
                $targetDn = $identifierAttribute.'='.$identifier.',ou='.$ou.','.$baseDn;
            } else {
                $targetDn = $identifierAttribute.'='.$identifier.','.$baseDn;
            }
        }

        $objectClasses = $rawPayload['objectClass'] ?? $rawPayload['objectclass'] ?? ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];

        if (is_string($objectClasses)) {
            $objectClasses = array_values(array_filter(array_map('trim', explode('|', $objectClasses))));
        }

        if (! is_array($objectClasses)) {
            $objectClasses = ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];
        }

        $attributes = $rawPayload;

        unset(
            $attributes['action'],
            $attributes['operation'],
            $attributes['ou'],
            $attributes['target_ou'],
            $attributes['source_ou'],
            $attributes['dn'],
            $attributes['DN']
        );

        return [
            'identifier_attribute' => $identifierAttribute,
            'identifier' => $identifier ? trim((string) $identifier) : null,
            'target_dn' => $targetDn ?: null,
            'object_classes' => $objectClasses,
            'attributes' => $attributes,
            'requested_action' => $this->determineRequestedAction($rawPayload, []),
            'raw_changetype' => strtolower((string) ($rawPayload['changetype'] ?? '')),
        ];
    }

    private function determineRequestedAction(array $rawPayload, array $mapped): string
    {
        $action = strtolower(trim((string) ($rawPayload['action'] ?? $rawPayload['operation'] ?? '')));

        if ($action === '') {
            $changetype = strtolower(trim((string) ($rawPayload['changetype'] ?? $mapped['raw_changetype'] ?? '')));

            $action = match ($changetype) {
                'modify' => 'update',
                'delete' => 'delete',
                'add' => 'create',
                default => 'create',
            };
        }

        return match ($action) {
            'edit', 'update', 'modify', 'replace' => 'update',
            'delete', 'remove' => 'delete',
            'skip' => 'skip',
            default => 'create',
        };
    }

    private function validateMappedPayload(ImportBatch $batch, array $mapped): array
    {
        $errors = [];
        $action = $mapped['requested_action'] ?? 'create';

        if (blank($mapped['identifier'])) {
            $errors[] = 'Missing identifier value.';
        }

        if (blank($mapped['target_dn'])) {
            $errors[] = 'Unable to determine target DN.';
        }

        if (! blank($mapped['target_dn']) && ! str_contains((string) $mapped['target_dn'], '=')) {
            $errors[] = 'Target DN format looks invalid.';
        }

        $attributes = $mapped['attributes'] ?? [];
        $objectClasses = $mapped['object_classes'] ?? [];

        $usesInetOrgPerson = collect($objectClasses)
            ->map(fn ($item) => strtolower((string) $item))
            ->contains('inetorgperson');

        if ($action === 'create' && $usesInetOrgPerson) {
            if (blank($attributes['sn'] ?? null)) {
                $errors[] = 'Missing required attribute sn for inetOrgPerson.';
            }

            if (blank($attributes['cn'] ?? null)) {
                $errors[] = 'Missing required attribute cn.';
            }
        }

        return $errors;
    }

    private function rowMessage(string $status, array $validationErrors, array $warnings, string $actionPlan): string
    {
        if ($validationErrors !== []) {
            return implode(' ', $validationErrors);
        }

        if ($warnings !== []) {
            return implode(' ', $warnings);
        }

        return match ($actionPlan) {
            'create' => 'Row is valid. LDAP entry will be created.',
            'update' => 'Row is valid. Existing LDAP entry will be updated.',
            'delete' => 'Row is valid. Existing LDAP entry will be deleted.',
            'skip' => 'Row will be skipped safely.',
            default => 'Row is valid for preview.',
        };
    }

    private function extractRdnValue(string $dn): ?string
    {
        $first = explode(',', $dn)[0] ?? '';

        if (! str_contains($first, '=')) {
            return null;
        }

        return trim(explode('=', $first, 2)[1] ?? '') ?: null;
    }

    private function auditPreview(ImportBatch $batch, string $status, string $message, float $startedAt): void
    {
        if (! class_exists(AuditLogger::class)) {
            return;
        }

        try {
            app(AuditLogger::class)->log(RedactsSensitiveData::redact([
                'module' => 'operations.imports',
                'action' => 'preview_import_batch',
                'status' => $status,
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'target_dn' => $batch->base_dn,
                'request_payload' => [
                    'import_type' => $batch->import_type,
                    'file_path' => $batch->file_path,
                    'existing_dn_check' => (bool) $batch->ldap_connection_id,
                    'all_ldap_preview_mode' => ! (bool) $batch->ldap_connection_id,
                    'skip_existing_create' => true,
                ],
                'after_value' => [
                    'total_rows' => $batch->total_rows,
                    'valid_rows' => $batch->valid_rows,
                    'invalid_rows' => $batch->invalid_rows,
                    'will_create_rows' => $batch->will_create_rows,
                    'will_update_rows' => $batch->will_update_rows,
                    'will_skip_rows' => $batch->will_skip_rows,
                    'will_fail_rows' => $batch->will_fail_rows,
                ],
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $status === 'failed' ? $message : null,
            ]));
        } catch (Throwable) {
            // Audit failure must never break preview.
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
