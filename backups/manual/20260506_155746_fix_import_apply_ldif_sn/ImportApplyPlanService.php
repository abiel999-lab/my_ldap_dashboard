<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Models\Operations\ImportBatch;
use App\Models\Operations\ImportRow;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportApplyPlanService
{
    public function generate(ImportBatch $batch, ?ImportApplyPlan $plan = null): array
    {
        $startedAt = microtime(true);

        try {
            $batch->refresh();

            $validation = $this->validateBatch($batch);

            if (! $validation['ok']) {
                return [
                    'ok' => false,
                    'message' => $validation['message'],
                    'plan' => null,
                ];
            }

            if (! $plan) {
                $plan = ImportApplyPlan::query()->create([
                    'import_batch_id' => $batch->id,
                    'ldap_connection_id' => $batch->ldap_connection_id,
                    'name' => 'Apply LDIF Dry Run - '.$batch->name,
                    'status' => 'running',
                    'plan_type' => 'ldif_dry_run',
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                    'started_at' => now(),
                    'message' => 'Generating LDIF apply dry run.',
                ]);
            } else {
                $plan->forceFill([
                    'status' => 'running',
                    'started_at' => now(),
                    'message' => 'Generating LDIF apply dry run.',
                ])->save();
            }

            $rows = $batch->rows()
                ->where('status', 'valid')
                ->where('action_plan', 'create')
                ->orderBy('row_number')
                ->get();

            $skippedRows = $batch->rows()
                ->whereIn('status', ['duplicate', 'invalid', 'conflict'])
                ->count();

            $ldif = $this->buildLdif($rows);

            $outputPath = $this->writePlanFile($plan, $ldif);
            $absolutePath = Storage::disk('local')->path($outputPath);
            $outputSize = File::exists($absolutePath) ? File::size($absolutePath) : 0;
            $outputHash = File::exists($absolutePath) ? hash_file('sha256', $absolutePath) : null;

            $plan->forceFill([
                'status' => 'success',
                'total_rows' => $batch->total_rows,
                'planned_create_rows' => $rows->count(),
                'planned_update_rows' => 0,
                'skipped_rows' => $skippedRows,
                'failed_rows' => $batch->invalid_rows,
                'output_disk' => 'local',
                'output_path' => $outputPath,
                'output_size_bytes' => $outputSize,
                'output_hash' => $outputHash,
                'message' => 'LDIF apply dry run generated successfully. No LDAP data was changed.',
                'metadata' => [
                    'source_import_batch_id' => $batch->id,
                    'source_import_batch_name' => $batch->name,
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => false,
                    'duration_ms' => $this->durationMs($startedAt),
                    'note' => 'This file is a generated apply plan only. It has not been executed against LDAP.',
                ],
                'finished_at' => now(),
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'generate_import_apply_ldif_dry_run',
                'status' => 'success',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'operation_job_id' => $plan->operation_job_id,
                'request_payload' => [
                    'import_batch_id' => $batch->id,
                    'import_batch_name' => $batch->name,
                    'plan_id' => $plan->id,
                    'safe_mode' => true,
                    'dry_run' => true,
                ],
                'after_value' => [
                    'planned_create_rows' => $plan->planned_create_rows,
                    'skipped_rows' => $plan->skipped_rows,
                    'failed_rows' => $plan->failed_rows,
                    'output_path' => $plan->output_path,
                    'output_size_bytes' => $plan->output_size_bytes,
                    'output_hash' => $plan->output_hash,
                ],
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [
                'ok' => true,
                'message' => 'LDIF apply dry run generated successfully.',
                'plan' => $plan,
            ];
        } catch (Throwable $exception) {
            if (isset($plan) && $plan) {
                $plan->forceFill([
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'finished_at' => now(),
                ])->save();
            }

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'generate_import_apply_ldif_dry_run',
                'status' => 'failed',
                'target_type' => ImportBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->base_dn,
                'ldap_connection_id' => $batch->ldap_connection_id,
                'error_message' => $exception->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'plan' => $plan ?? null,
            ];
        }
    }

    private function validateBatch(ImportBatch $batch): array
    {
        if (! in_array($batch->status, ['previewed', 'previewed_with_errors', 'ready_to_apply'], true)) {
            return [
                'ok' => false,
                'message' => 'Import batch must be previewed before generating apply LDIF.',
            ];
        }

        if ($batch->valid_rows <= 0) {
            return [
                'ok' => false,
                'message' => 'No valid rows available to generate LDIF apply plan.',
            ];
        }

        if (! $batch->preview_only) {
            return [
                'ok' => false,
                'message' => 'Import batch must remain preview-only at this stage.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Import batch can generate apply LDIF dry run.',
        ];
    }

    private function buildLdif($rows): string
    {
        $lines = [];

        $lines[] = '# Generated LDIF apply dry run';
        $lines[] = '# No LDAP data has been changed';
        $lines[] = '# Generated at: '.now()->toDateTimeString();
        $lines[] = '';

        /** @var ImportRow $row */
        foreach ($rows as $row) {
            $mapped = $row->mapped_payload ?? [];
            $attributes = $mapped['attributes'] ?? [];
            $targetDn = $mapped['target_dn'] ?? $row->target_dn;

            if (blank($targetDn)) {
                continue;
            }

            $lines[] = 'dn: '.$this->escapeLdifValue((string) $targetDn);
            $lines[] = 'changetype: add';

            $objectClasses = $mapped['object_classes'] ?? ($attributes['objectClass'] ?? []);

            if (is_string($objectClasses)) {
                $objectClasses = [$objectClasses];
            }

            if (! is_array($objectClasses) || $objectClasses === []) {
                $objectClasses = ['top', 'inetOrgPerson'];
            }

            foreach ($objectClasses as $objectClass) {
                $objectClass = trim((string) $objectClass);

                if ($objectClass !== '') {
                    $lines[] = 'objectClass: '.$this->escapeLdifValue($objectClass);
                }
            }

            foreach ($attributes as $key => $value) {
                $key = trim((string) $key);

                if ($key === '' || strtolower($key) === 'dn' || strtolower($key) === 'objectclass') {
                    continue;
                }

                if (is_array($value)) {
                    foreach ($value as $singleValue) {
                        $singleValue = trim((string) $singleValue);

                        if ($singleValue !== '') {
                            $lines[] = $key.': '.$this->escapeLdifValue($singleValue);
                        }
                    }
                } else {
                    $singleValue = trim((string) $value);

                    if ($singleValue !== '') {
                        $lines[] = $key.': '.$this->escapeLdifValue($singleValue);
                    }
                }
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function writePlanFile(ImportApplyPlan $plan, string $content): string
    {
        $safeName = str($plan->name)
            ->slug('_')
            ->limit(80, '')
            ->toString();

        $path = 'imports/apply-plans/'.now()->format('Ymd_His').'_plan_'.$plan->id.'_'.$safeName.'.ldif';

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function escapeLdifValue(string $value): string
    {
        return trim($value);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
