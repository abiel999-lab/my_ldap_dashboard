<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use Illuminate\Support\Facades\Schema;

class ImportApplySafetyGateService
{
    public function validateForApproval(ImportApplyPlan $plan): array
    {
        return $this->allow($plan);
    }

    public function validateForDryRun(ImportApplyPlan $plan): array
    {
        return $this->allow($plan);
    }

    public function validateForRealApply(ImportApplyPlan $plan): array
    {
        return $this->allow($plan);
    }

    public function approve(ImportApplyPlan $plan, ?int $userId = null, ?string $note = null): array
    {
        $updates = [
            'approval_status' => 'approved',
            'apply_blocked_reason' => null,
        ];

        if (Schema::hasColumn('import_apply_plans', 'approved_at')) {
            $updates['approved_at'] = now();
        }

        if (Schema::hasColumn('import_apply_plans', 'approval_note')) {
            $updates['approval_note'] = $note ?: 'Auto-approved by simplified import flow.';
        }

        if (Schema::hasColumn('import_apply_plans', 'approved_by_user_id')) {
            $updates['approved_by_user_id'] = $userId;
        }

        if (Schema::hasColumn('import_apply_plans', 'approved_by')) {
            $updates['approved_by'] = $userId;
        }

        if (Schema::hasColumn('import_apply_plans', 'approved_by_user')) {
            $updates['approved_by_user'] = $userId;
        }

        $plan->forceFill($updates)->save();

        return $this->allow($plan);
    }

    public function allow(ImportApplyPlan $plan): array
    {
        return [
            'ok' => true,
            'allowed' => true,
            'message' => 'Allowed by simplified import flow.',
        ];
    }

    public function check(ImportApplyPlan $plan): array
    {
        return $this->allow($plan);
    }

    public function validate(ImportApplyPlan $plan): array
    {
        return $this->allow($plan);
    }
}
