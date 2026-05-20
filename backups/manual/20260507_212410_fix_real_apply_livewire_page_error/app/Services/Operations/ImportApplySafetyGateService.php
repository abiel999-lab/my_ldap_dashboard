<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;

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
        $plan->forceFill([
            'approval_status' => 'approved',
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_note' => $note ?: 'Auto-approved by simplified import flow.',
            'apply_blocked_reason' => null,
        ])->save();

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
