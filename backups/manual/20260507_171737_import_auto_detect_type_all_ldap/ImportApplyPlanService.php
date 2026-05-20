<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\ImportRow;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportApplyPlanService
{
    public function generate(ImportBatch $batch, ?ImportApplyPlan $plan = null): array
    {
        $startedAt = microtime(true);

        $batch->loadMissing('ldapConnection');

        $errors = $this->validateBatch($batch);

        if ($errors !== []) {
            return [
                'ok' => false,
                'message' => implode(' ', $errors),
                'errors' => $errors,
            ];
        }

        try {
            $rows = $batch->rows()
                ->whereIn('action_plan', ['create', 'update', 'modify', 'delete'])
                ->where('status', 'valid')
                ->orderBy('row_number')
                ->get();

            $plan ??= ImportApplyPlan::query()->create([
                'import_batch_id' => $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'name' => 'Apply LDIF Plan - '.$batch->name,
                'status' => 'generating',
                'plan_type' => 'ldif_apply_plan',
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'created_by' => Auth::id(),
                'metadata' => [
                    'source_import_batch_id' => $batch->id,
                    'source_import_batch_name' => $batch->name,
                    'uses_ldapmodify_changetype' => true,
                ],
            ]);

            $ldif = $this->buildLdif($rows);
            $outputPath = $this->writePlanFile($plan, $ldif);
            $outputFullPath = storage_path('app/private/'.$outputPath);

            $plannedCreate = $rows->where('action_plan', 'create')->count();
            $plannedUpdate = $rows->whereIn('action_plan', ['update', 'modify'])->count();
            $plannedDelete = $rows->where('action_plan', 'delete')->count();
            $skippedRows = $batch->rows()->whereIn('action_plan', ['skip', 'fail'])->count();

            $plan->forceFill([
                'status' => 'success',
                'total_rows' => $batch->total_rows,
                'planned_create_rows' => $plannedCreate,
                'planned_update_rows' => $plannedUpdate + $plannedDelete,
                'skipped_rows' => $skippedRows,
                'failed_rows' => $batch->will_fail_rows,
                'output_disk' => 'local',
                'output_path' => $outputPath,
                'output_size_bytes' => is_file($outputFullPath) ? filesize($outputFullPath) : strlen($ldif),
                'output_hash' => hash('sha256', $ldif),
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => $plannedDelete > 0,
                'message' => 'LDIF apply plan generated successfully. No LDAP data was changed.',
                'metadata' => array_merge($plan->metadata ?? [], [
                    'duration_ms' => $this->durationMs($startedAt),
                    'planned_delete_rows' => $plannedDelete,
                    'ldap_command' => 'ldapmodify',
                    'note' => 'This file is a generated LDIF apply plan only. It has not been executed against LDAP.',
                ]),
                'started_at' => $plan->started_at ?: now(),
                'finished_at' => now(),
            ])->save();

            $this->audit($batch, $plan, 'success', null, $startedAt);

            return [
                'ok' => true,
                'message' => $plan->message,
                'plan_id' => $plan->id,
                'output_path' => $outputPath,
            ];
        } catch (Throwable $exception) {
            if (isset($plan)) {
                $plan->forceFill([
                    'status' => 'failed',
                    'message' => 'Failed to generate LDIF apply plan.',
                    'metadata' => array_merge($plan->metadata ?? [], [
                        'error' => $exception->getMessage(),
                    ]),
                    'finished_at' => now(),
                ])->save();
            }

            $this->audit($batch, $plan ?? null, 'failed', $exception->getMessage(), $startedAt);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function validateBatch(ImportBatch $batch): array
    {
        $errors = [];

        if (! $batch->ldap_connection_id) {
            $errors[] = 'LDAP connection is required before generating apply plan.';
        }

        if ($batch->rows()->count() <= 0) {
            $errors[] = 'Import batch has no preview rows. Run preview first.';
        }

        if ($batch->will_create_rows <= 0 && $batch->will_update_rows <= 0) {
            $actionRows = $batch->rows()
                ->whereIn('action_plan', ['create', 'update', 'modify', 'delete'])
                ->where('status', 'valid')
                ->count();

            if ($actionRows <= 0) {
                $errors[] = 'No applicable create/update/delete rows found. Nothing to apply.';
            }
        }

        return $errors;
    }

    private function buildLdif($rows): string
    {
        $blocks = [];

        /** @var ImportRow $row */
        foreach ($rows as $row) {
            $mapped = $row->mapped_payload ?? [];
            $raw = $row->raw_payload ?? [];
            $targetDn = $row->target_dn;
            $action = $row->action_plan;

            if (blank($targetDn)) {
                continue;
            }

            if ($action === 'create') {
                $blocks[] = $this->buildAddBlock($targetDn, $mapped);

                continue;
            }

            if (in_array($action, ['update', 'modify'], true)) {
                $blocks[] = $this->buildModifyBlock($targetDn, $mapped, $raw);

                continue;
            }

            if ($action === 'delete') {
                $blocks[] = implode(PHP_EOL, [
                    'dn: '.$targetDn,
                    'changetype: delete',
                ]);

                continue;
            }
        }

        return implode(PHP_EOL.PHP_EOL, array_filter($blocks)).PHP_EOL;
    }

    private function buildAddBlock(string $targetDn, array $mapped): string
    {
        $objectClasses = $mapped['object_classes'] ?? ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];
        $attributes = $mapped['attributes'] ?? [];

        if (! is_array($objectClasses)) {
            $objectClasses = ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];
        }

        if (collect($objectClasses)->map(fn ($item) => strtolower((string) $item))->contains('inetorgperson')) {
            if (blank($attributes['sn'] ?? null)) {
                $attributes['sn'] = $this->deriveSurnameFromAttributes($attributes);
            }

            if (blank($attributes['cn'] ?? null)) {
                $attributes['cn'] = $attributes['uid'] ?? $attributes['mail'] ?? 'Imported User';
            }
        }

        $lines = [
            'dn: '.$targetDn,
            'changetype: add',
        ];

        foreach ($objectClasses as $objectClass) {
            $objectClass = trim((string) $objectClass);

            if ($objectClass !== '') {
                $lines[] = 'objectClass: '.$this->escapeLdifValue($objectClass);
            }
        }

        foreach ($attributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '' || in_array(strtolower($attribute), ['dn', 'changetype', 'action', 'operation', 'ou', 'target_ou'], true)) {
                continue;
            }

            if (strtolower($attribute) === 'objectclass') {
                continue;
            }

            foreach ($this->asValues($value) as $singleValue) {
                $lines[] = $attribute.': '.$this->escapeLdifValue($singleValue);
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildModifyBlock(string $targetDn, array $mapped, array $raw): string
    {
        $attributes = $mapped['attributes'] ?? [];
        $replaceList = $raw['replace'] ?? [];

        if (is_string($replaceList)) {
            $replaceList = [$replaceList];
        }

        if (! is_array($replaceList) || $replaceList === []) {
            $replaceList = array_keys($attributes);
        }

        $lines = [
            'dn: '.$targetDn,
            'changetype: modify',
        ];

        foreach ($replaceList as $attribute) {
            $attribute = trim((string) $attribute);

            if ($attribute === '' || in_array(strtolower($attribute), ['dn', 'changetype', 'action', 'operation', 'ou', 'target_ou', 'uid', 'objectclass', 'replace'], true)) {
                continue;
            }

            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }

            $lines[] = 'replace: '.$attribute;

            foreach ($this->asValues($attributes[$attribute]) as $singleValue) {
                $lines[] = $attribute.': '.$this->escapeLdifValue($singleValue);
            }

            $lines[] = '-';
        }

        return implode(PHP_EOL, $lines);
    }

    private function asValues(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item): string => trim((string) $item))
                ->filter(fn (string $item): bool => $item !== '')
                ->values()
                ->all();
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
    }

    private function deriveSurnameFromAttributes(array $attributes): string
    {
        $cn = trim((string) ($attributes['cn'] ?? $attributes['uid'] ?? $attributes['mail'] ?? 'Imported'));

        $parts = preg_split('/\s+/', $cn) ?: [];

        return trim((string) end($parts)) ?: $cn;
    }

    private function writePlanFile(ImportApplyPlan $plan, string $content): string
    {
        $safeName = Str::slug($plan->name ?: 'import-apply-plan');
        $path = 'imports/apply-plans/'.now()->format('Ymd_His').'_plan_'.$plan->id.'_'.$safeName.'.ldif';

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function escapeLdifValue(string $value): string
    {
        return str_replace(["\r", "\n"], [' ', ' '], $value);
    }

    private function audit(ImportBatch $batch, ?ImportApplyPlan $plan, string $status, ?string $error, float $startedAt): void
    {
        if (! class_exists(AuditLogger::class)) {
            return;
        }

        try {
            app(AuditLogger::class)->log(RedactsSensitiveData::redact([
                'module' => 'operations.imports',
                'action' => 'generate_import_apply_ldif_plan',
                'status' => $status,
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'target_dn' => $batch->base_dn,
                'request_payload' => [
                    'import_batch_id' => $batch->id,
                    'import_type' => $batch->import_type,
                    'uses_ldapmodify_changetype' => true,
                ],
                'after_value' => [
                    'import_apply_plan_id' => $plan?->id,
                    'output_path' => $plan?->output_path,
                    'planned_create_rows' => $plan?->planned_create_rows,
                    'planned_update_rows' => $plan?->planned_update_rows,
                    'skipped_rows' => $plan?->skipped_rows,
                    'failed_rows' => $plan?->failed_rows,
                ],
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $error,
            ]));
        } catch (Throwable) {
            // Audit failure must not break plan generation.
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
