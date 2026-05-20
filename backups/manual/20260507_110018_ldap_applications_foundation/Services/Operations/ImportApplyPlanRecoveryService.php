<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportApplyPlanRecoveryService
{
    public function archiveFailedPlan(ImportApplyPlan $plan, string $reason): array
    {
        try {
            $plan->refresh();

            if (! $plan->canArchivePlan()) {
                return [
                    'ok' => false,
                    'message' => 'This plan cannot be archived. Only failed/rejected non-archived plans can be archived.',
                ];
            }

            if (blank($reason)) {
                return [
                    'ok' => false,
                    'message' => 'Archive reason is required.',
                ];
            }

            $oldStatus = $plan->status;

            $plan->forceFill([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => Auth::id(),
                'archive_reason' => $reason,
                'message' => 'Plan archived for recovery. LDAP data was not changed by this archive action.',
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'archive_failed_import_apply_plan',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'target_dn' => $plan->importBatch?->base_dn,
                'ldap_connection_id' => $plan->ldap_connection_id ?? $plan->importBatch?->ldap_connection_id,
                'operation_job_id' => $plan->operation_job_id,
                'before_value' => [
                    'status' => $oldStatus,
                    'archived_at' => null,
                ],
                'after_value' => [
                    'status' => $plan->status,
                    'archived_at' => $plan->archived_at?->toDateTimeString(),
                    'archived_by' => $plan->archived_by,
                    'archive_reason' => $plan->archive_reason,
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Plan archived successfully.',
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'archive_failed_import_apply_plan',
                'status' => 'failed',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function createReplacementPlan(ImportApplyPlan $failedPlan, string $note): array
    {
        try {
            $failedPlan->refresh();

            if (! $failedPlan->canCreateReplacementPlan()) {
                return [
                    'ok' => false,
                    'message' => 'This plan cannot create a replacement plan.',
                    'replacement_plan' => null,
                ];
            }

            if (! $failedPlan->importBatch) {
                return [
                    'ok' => false,
                    'message' => 'Original import batch is missing.',
                    'replacement_plan' => null,
                ];
            }

            $result = app(ImportApplyPlanService::class)->generate($failedPlan->importBatch);

            if (! $result['ok'] || ! $result['plan']) {
                return [
                    'ok' => false,
                    'message' => $result['message'] ?? 'Failed to generate replacement plan.',
                    'replacement_plan' => null,
                ];
            }

            /** @var ImportApplyPlan $replacement */
            $replacement = $result['plan'];

            $replacement->forceFill([
                'replacement_of_plan_id' => $failedPlan->id,
                'recovery_note' => $note ?: 'Replacement plan generated from failed plan #'.$failedPlan->id.'.',
                'message' => 'Replacement apply plan generated. LDAP data has not been changed.',
            ])->save();

            $failedPlan->forceFill([
                'replaced_by_plan_id' => $replacement->id,
                'recovery_note' => $note ?: 'Replaced by plan #'.$replacement->id.'.',
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'create_replacement_import_apply_plan',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $failedPlan->id,
                'target_dn' => $failedPlan->importBatch?->base_dn,
                'ldap_connection_id' => $failedPlan->ldap_connection_id ?? $failedPlan->importBatch?->ldap_connection_id,
                'operation_job_id' => $failedPlan->operation_job_id,
                'request_payload' => [
                    'failed_plan_id' => $failedPlan->id,
                    'replacement_plan_id' => $replacement->id,
                    'import_batch_id' => $failedPlan->import_batch_id,
                    'note' => $note,
                    'ldap_was_changed' => false,
                ],
                'after_value' => [
                    'failed_plan_status' => $failedPlan->status,
                    'failed_plan_replaced_by_plan_id' => $failedPlan->replaced_by_plan_id,
                    'replacement_plan_status' => $replacement->status,
                    'replacement_plan_output_path' => $replacement->output_path,
                    'replacement_plan_output_hash' => $replacement->output_hash,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Replacement plan created successfully.',
                'replacement_plan' => $replacement,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'create_replacement_import_apply_plan',
                'status' => 'failed',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $failedPlan->id,
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'replacement_plan' => null,
            ];
        }
    }
}
