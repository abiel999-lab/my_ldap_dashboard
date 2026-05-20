<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\ImportRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportApplyPlanService
{
    public function generate(ImportBatch $batch, ?ImportApplyPlan $plan = null): array
    {
        $startedAt = microtime(true);

        $batch->loadMissing('ldapConnection');

        if (! $batch->ldap_connection_id) {
            return [
                'ok' => false,
                'message' => 'LDAP connection is required before generating apply plan.',
            ];
        }

        if ($batch->rows()->count() <= 0) {
            return [
                'ok' => false,
                'message' => 'Import batch has no preview rows. Run preview first.',
            ];
        }

        try {
            $rows = $batch->rows()
                ->where('status', 'valid')
                ->whereIn('action_plan', ['create', 'update', 'modify', 'delete'])
                ->orderBy('row_number')
                ->get();

            if ($rows->isEmpty()) {
                return [
                    'ok' => false,
                    'message' => 'No valid create/update/delete rows found. Run preview again.',
                ];
            }

            $plan ??= ImportApplyPlan::query()->create([
                'import_batch_id' => $batch->id,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'name' => 'Apply LDIF Plan - '.$batch->name,
                'status' => 'generating',
                'plan_type' => 'ldif_apply_plan',
                'approval_status' => 'not_requested',
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => false,
                'created_by' => Auth::id(),
                'started_at' => now(),
                'metadata' => [
                    'source_import_batch_id' => $batch->id,
                    'source_import_batch_name' => $batch->name,
                    'generator_version' => 'hard_fix_dual_location_ldif_v1',
                ],
            ]);

            $ldif = $this->buildLdif($batch, $rows);

            if (! $this->containsSupportedChangeType($ldif)) {
                $plan->forceFill([
                    'status' => 'failed',
                    'message' => 'Generated LDIF is empty or does not contain changetype: add, modify, or delete.',
                    'failed_rows' => $rows->count(),
                    'finished_at' => now(),
                    'metadata' => array_merge($plan->metadata ?? [], [
                        'duration_ms' => $this->durationMs($startedAt),
                        'debug_ldif_preview' => mb_substr($ldif, 0, 2000),
                    ]),
                ])->save();

                return [
                    'ok' => false,
                    'message' => $plan->message,
                    'plan_id' => $plan->id,
                    'ldif_preview' => $ldif,
                ];
            }

            $outputPath = $this->writePlanFileBothLocations($plan, $ldif);
            $projectPath = base_path($outputPath);

            $plannedCreate = $rows->where('action_plan', 'create')->count();
            $plannedUpdate = $rows->whereIn('action_plan', ['update', 'modify'])->count();
            $plannedDelete = $rows->where('action_plan', 'delete')->count();
            $skippedRows = $batch->rows()->whereIn('action_plan', ['skip', 'fail'])->count();

            $plan->forceFill([
                'status' => 'success',
                'approval_status' => $plan->approval_status ?: 'not_requested',
                'total_rows' => $batch->total_rows,
                'planned_create_rows' => $plannedCreate,
                'planned_update_rows' => $plannedUpdate,
                'skipped_rows' => $skippedRows,
                'failed_rows' => $batch->will_fail_rows,
                'output_disk' => 'local',
                'output_path' => $outputPath,
                'output_size_bytes' => is_file($projectPath) ? filesize($projectPath) : strlen($ldif),
                'output_hash' => hash('sha256', $ldif),
                'safe_mode' => true,
                'dry_run' => true,
                'destructive' => $plannedDelete > 0,
                'message' => 'LDIF apply plan generated successfully. No LDAP data was changed.',
                'metadata' => array_merge($plan->metadata ?? [], [
                    'duration_ms' => $this->durationMs($startedAt),
                    'generated_change_types' => $this->detectChangeTypes($ldif),
                    'storage_private_path' => storage_path('app/private/'.$outputPath),
                    'project_runtime_path' => base_path($outputPath),
                    'ldap_command' => 'ldapmodify',
                ]),
                'finished_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'message' => $plan->message,
                'plan_id' => $plan->id,
                'output_path' => $outputPath,
                'change_types' => $this->detectChangeTypes($ldif),
            ];
        } catch (Throwable $exception) {
            if (isset($plan)) {
                $plan->forceFill([
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'finished_at' => now(),
                ])->save();
            }

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function buildLdif(ImportBatch $batch, $rows): string
    {
        if ($batch->import_type === 'ldif') {
            $originalLdif = $this->readOriginalUploadedLdif($batch);

            if ($this->containsSupportedChangeType($originalLdif)) {
                return trim($originalLdif).PHP_EOL;
            }
        }

        $blocks = [];

        foreach ($rows as $row) {
            /** @var ImportRow $row */
            $targetDn = trim((string) $row->target_dn);
            $action = strtolower(trim((string) $row->action_plan));

            if ($targetDn === '') {
                continue;
            }

            $mapped = is_array($row->mapped_payload) ? $row->mapped_payload : [];
            $raw = is_array($row->raw_payload) ? $row->raw_payload : [];
            $attributes = $this->normalizeAttributes($mapped['attributes'] ?? $raw);

            if ($action === 'create') {
                $blocks[] = $this->buildAddBlock($targetDn, $mapped, $attributes);
                continue;
            }

            if (in_array($action, ['update', 'modify'], true)) {
                $modifyBlock = $this->buildModifyBlock($targetDn, $attributes);

                if ($modifyBlock !== '') {
                    $blocks[] = $modifyBlock;
                }

                continue;
            }

            if ($action === 'delete') {
                $blocks[] = implode(PHP_EOL, [
                    'dn: '.$targetDn,
                    'changetype: delete',
                ]);
            }
        }

        return trim(implode(PHP_EOL.PHP_EOL, array_filter($blocks))).PHP_EOL;
    }

    private function readOriginalUploadedLdif(ImportBatch $batch): string
    {
        $filePath = trim((string) $batch->file_path);

        if ($filePath === '') {
            return '';
        }

        $candidates = [
            storage_path('app/private/'.$filePath),
            storage_path('app/'.$filePath),
            base_path($filePath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return (string) file_get_contents($candidate);
            }
        }

        return '';
    }

    private function buildAddBlock(string $targetDn, array $mapped, array $attributes): string
    {
        $objectClasses = $mapped['object_classes'] ?? ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];

        if (is_string($objectClasses)) {
            $objectClasses = [$objectClasses];
        }

        if (! is_array($objectClasses) || $objectClasses === []) {
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

            if ($this->isMetaAttribute($attribute)) {
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

    private function buildModifyBlock(string $targetDn, array $attributes): string
    {
        $replaceable = [];

        foreach ($attributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '') {
                continue;
            }

            if ($this->isMetaAttribute($attribute)) {
                continue;
            }

            if (in_array(strtolower($attribute), ['uid', 'objectclass', 'userpassword'], true)) {
                continue;
            }

            $values = $this->asValues($value);

            if ($values === []) {
                continue;
            }

            $replaceable[$attribute] = $values;
        }

        if ($replaceable === []) {
            return '';
        }

        $lines = [
            'dn: '.$targetDn,
            'changetype: modify',
        ];

        foreach ($replaceable as $attribute => $values) {
            $lines[] = 'replace: '.$attribute;

            foreach ($values as $singleValue) {
                $lines[] = $attribute.': '.$this->escapeLdifValue($singleValue);
            }

            $lines[] = '-';
        }

        return implode(PHP_EOL, $lines);
    }

    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function isMetaAttribute(string $attribute): bool
    {
        return in_array(strtolower($attribute), [
            'dn',
            'changetype',
            'action',
            'operation',
            'ou',
            'target_ou',
            'source_ou',
            'replace',
        ], true);
    }

    private function writePlanFileBothLocations(ImportApplyPlan $plan, string $content): string
    {
        $safeName = Str::slug($plan->name ?: 'import-apply-plan');
        $path = 'imports/apply-plans/'.now()->format('Ymd_His').'_plan_'.$plan->id.'_'.$safeName.'.ldif';

        Storage::disk('local')->put($path, $content);

        $privatePath = storage_path('app/private/'.$path);
        File::ensureDirectoryExists(dirname($privatePath));
        file_put_contents($privatePath, $content);

        $projectPath = base_path($path);
        File::ensureDirectoryExists(dirname($projectPath));
        file_put_contents($projectPath, $content);

        $appPath = storage_path('app/'.$path);
        File::ensureDirectoryExists(dirname($appPath));
        file_put_contents($appPath, $content);

        return $path;
    }

    private function containsSupportedChangeType(string $ldifContent): bool
    {
        $normalized = strtolower($ldifContent);

        return str_contains($normalized, 'changetype: add')
            || str_contains($normalized, 'changetype: modify')
            || str_contains($normalized, 'changetype: delete');
    }

    private function detectChangeTypes(string $ldifContent): array
    {
        $normalized = strtolower($ldifContent);
        $types = [];

        foreach (['add', 'modify', 'delete'] as $type) {
            if (str_contains($normalized, 'changetype: '.$type)) {
                $types[] = $type;
            }
        }

        return $types;
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

    private function escapeLdifValue(string $value): string
    {
        return str_replace(["\r", "\n"], [' ', ' '], $value);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
