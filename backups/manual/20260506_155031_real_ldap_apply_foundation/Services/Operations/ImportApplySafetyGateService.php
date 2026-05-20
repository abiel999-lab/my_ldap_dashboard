<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportApplySafetyGateService
{
    public function requestApproval(ImportApplyPlan $plan, ?string $note = null): array
    {
        try {
            $plan->refresh();

            if (! $plan->canRequestApproval()) {
                return [
                    'ok' => false,
                    'message' => 'This apply plan cannot request approval. It must be successful and have an output LDIF file.',
                ];
            }

            $blockedReason = $this->detectBlockingReason($plan);

            $plan->forceFill([
                'approval_status' => 'pending',
                'approval_note' => $note ?: 'Approval requested for generated LDIF apply plan.',
                'apply_blocked_reason' => $blockedReason,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'request_import_apply_approval',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'operation_job_id' => $plan->operation_job_id,
                'request_payload' => [
                    'approval_status' => $plan->approval_status,
                    'approval_note' => $plan->approval_note,
                    'apply_blocked_reason' => $plan->apply_blocked_reason,
                    'planned_create_rows' => $plan->planned_create_rows,
                    'planned_update_rows' => $plan->planned_update_rows,
                    'skipped_rows' => $plan->skipped_rows,
                    'failed_rows' => $plan->failed_rows,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Approval requested.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function approve(ImportApplyPlan $plan, string $note): array
    {
        try {
            $plan->refresh();

            if (! $plan->canApprove()) {
                return [
                    'ok' => false,
                    'message' => 'This apply plan cannot be approved.',
                ];
            }

            if (blank($note)) {
                return [
                    'ok' => false,
                    'message' => 'Approval note is required.',
                ];
            }

            $blockedReason = $this->detectBlockingReason($plan);

            if ($blockedReason !== null) {
                return [
                    'ok' => false,
                    'message' => $blockedReason,
                ];
            }

            $plan->forceFill([
                'approval_status' => 'approved',
                'approval_note' => $note,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'apply_blocked_reason' => null,
                'message' => 'Apply plan approved for future LDAP apply step. LDAP data has not been changed yet.',
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'approve_import_apply_plan',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'operation_job_id' => $plan->operation_job_id,
                'request_payload' => [
                    'approval_note' => $note,
                    'approved_by' => Auth::id(),
                    'approved_at' => $plan->approved_at?->toDateTimeString(),
                    'planned_create_rows' => $plan->planned_create_rows,
                    'planned_update_rows' => $plan->planned_update_rows,
                    'skipped_rows' => $plan->skipped_rows,
                    'failed_rows' => $plan->failed_rows,
                    'output_path' => $plan->output_path,
                    'output_hash' => $plan->output_hash,
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Apply plan approved. LDAP data has not been changed yet.',
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'approve_import_apply_plan',
                'status' => 'failed',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'operation_job_id' => $plan->operation_job_id,
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function reject(ImportApplyPlan $plan, string $reason): array
    {
        try {
            $plan->refresh();

            if (blank($reason)) {
                return [
                    'ok' => false,
                    'message' => 'Rejection reason is required.',
                ];
            }

            $plan->forceFill([
                'approval_status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'message' => 'Apply plan rejected. LDAP data has not been changed.',
            ])->save();

            app(AuditLogger::class)->log([
                'module' => 'operations.import',
                'action' => 'reject_import_apply_plan',
                'status' => 'success',
                'target_type' => ImportApplyPlan::class,
                'target_key' => (string) $plan->id,
                'operation_job_id' => $plan->operation_job_id,
                'request_payload' => [
                    'rejection_reason' => $reason,
                    'rejected_by' => Auth::id(),
                    'rejected_at' => $plan->rejected_at?->toDateTimeString(),
                    'ldap_was_changed' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Apply plan rejected.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function detectBlockingReason(ImportApplyPlan $plan): ?string
    {
        if ($plan->status !== 'success') {
            return 'Apply plan must be successful before approval.';
        }

        if (! $plan->hasOutputFile()) {
            return 'Apply plan output LDIF file is missing.';
        }

        if (! $plan->safe_mode || ! $plan->dry_run || $plan->destructive) {
            return 'Apply plan safety flags are invalid. It must be safe_mode=true, dry_run=true, destructive=false.';
        }

        if ($plan->planned_create_rows <= 0 && $plan->planned_update_rows <= 0) {
            return 'No planned create/update rows are available.';
        }

        /*
         * We intentionally allow skipped/failed rows for approval only if the generated LDIF
         * contains valid planned rows. The rejected/invalid rows are not included in the LDIF.
         */
        return null;
    }
}
