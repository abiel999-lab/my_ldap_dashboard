<?php

namespace App\Support\Ui;

class StatusLabel
{
    public static function importBatch(?string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'queued' => 'Queued',
            'running' => 'Running',
            'previewing' => 'Previewing',
            'previewed' => 'Preview Completed',
            'previewed_with_errors' => 'Preview Completed With Issues',
            'ready_to_apply' => 'Ready To Apply',
            'failed' => 'Failed',
            default => self::humanize($status),
        };
    }

    public static function importBatchColor(?string $status): string
    {
        return match ($status) {
            'draft' => 'gray',
            'queued', 'previewing', 'running' => 'info',
            'previewed', 'ready_to_apply' => 'success',
            'previewed_with_errors' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function importApplyPlan(?string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'running' => 'Generating Plan',
            'success' => 'Plan Generated',
            'failed' => 'Plan Generation Failed',

            'dry_run_verified' => 'Dry Run Verified',
            'dry_run_failed' => 'Dry Run Failed',

            'apply_running' => 'Applying To LDAP',
            'applied' => 'Applied To LDAP',
            'apply_failed' => 'LDAP Apply Failed',

            'verified_applied' => 'Applied & Verified',
            'post_apply_verification_failed' => 'Post-Apply Verification Failed',

            'archived' => 'Archived',
            'rejected' => 'Rejected',
            default => self::humanize($status),
        };
    }

    public static function importApplyPlanColor(?string $status): string
    {
        return match ($status) {
            'draft', 'archived' => 'gray',
            'running', 'apply_running' => 'info',
            'success', 'dry_run_verified', 'applied', 'verified_applied' => 'success',
            'failed', 'dry_run_failed', 'apply_failed', 'post_apply_verification_failed', 'rejected' => 'danger',
            default => 'gray',
        };
    }

    public static function approval(?string $status): string
    {
        return match ($status) {
            'not_requested' => 'Not Requested',
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => self::humanize($status),
        };
    }

    public static function approvalColor(?string $status): string
    {
        return match ($status) {
            'not_requested' => 'gray',
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'gray',
        };
    }

    private static function humanize(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'N/A';
        }

        return str($value)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }
}
