<?php

namespace App\Services\Operations;

use App\Models\Operations\ImportApplyPlan;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class FastImportApplyService
{
    public function apply(ImportApplyPlan $plan): array
    {
        $plan->refresh();

        $validation = $this->validatePlan($plan);

        if (! ($validation['ok'] ?? false)) {
            $plan->forceFill([
                'status' => 'failed',
                'apply_blocked_reason' => $validation['message'] ?? 'Import apply plan is not valid.',
                'real_apply_error_message' => $validation['message'] ?? 'Import apply plan is not valid.',
                'message' => $validation['message'] ?? 'Import apply plan is not valid.',
                'finished_at' => now(),
            ])->save();

            return $validation;
        }

        try {
            DB::transaction(function () use ($plan, $validation) {
                $hasDelete = (bool) ($validation['has_delete'] ?? false);

                $plan->forceFill([
                    'safe_mode' => true,
                    'dry_run' => true,
                    'destructive' => $hasDelete,
                    'approval_status' => 'approved',
                    'approved_at' => now(),
                    'approved_by_user_id' => auth()->id(),
                    'approval_note' => 'Auto-approved by simplified import flow. Preview and LDIF plan were already generated.',
                    'apply_blocked_reason' => null,
                    'real_apply_error_message' => null,
                    'message' => 'Fast import apply started.',
                ])->save();
            });

            $executor = app(LdapRealApplyExecutor::class);

            $result = $this->callExecutor($executor, $plan->fresh());

            $plan->refresh();

            $ok = $this->resultLooksSuccessful($result, $plan);

            if (! $ok) {
                return [
                    'ok' => false,
                    'message' => $this->extractMessage($result, $plan) ?: 'Real apply failed. Check Command Executions.',
                    'plan_id' => $plan->id,
                ];
            }

            return [
                'ok' => true,
                'message' => 'Import applied successfully.',
                'plan_id' => $plan->id,
            ];
        } catch (Throwable $exception) {
            $plan->forceFill([
                'status' => 'failed',
                'real_apply_error_message' => $exception->getMessage(),
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'plan_id' => $plan->id,
            ];
        }
    }

    public function validatePlan(ImportApplyPlan $plan): array
    {
        if (! $plan->ldap_connection_id) {
            return [
                'ok' => false,
                'message' => 'LDAP connection is required before real apply.',
            ];
        }

        if (! $plan->output_path) {
            return [
                'ok' => false,
                'message' => 'Apply plan output LDIF path is missing.',
            ];
        }

        $content = $this->readLdif($plan);

        if (trim($content) === '') {
            return [
                'ok' => false,
                'message' => 'Apply plan LDIF file is missing or empty.',
            ];
        }

        $normalized = strtolower($content);

        $hasAdd = str_contains($normalized, 'changetype: add');
        $hasModify = str_contains($normalized, 'changetype: modify');
        $hasDelete = str_contains($normalized, 'changetype: delete');

        if (! $hasAdd && ! $hasModify && ! $hasDelete) {
            return [
                'ok' => false,
                'message' => 'Apply plan LDIF does not contain changetype: add, modify, or delete.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Apply plan is valid.',
            'has_add' => $hasAdd,
            'has_modify' => $hasModify,
            'has_delete' => $hasDelete,
        ];
    }

    public function readLdif(ImportApplyPlan $plan): string
    {
        $outputPath = trim((string) $plan->output_path);

        if ($outputPath === '') {
            return '';
        }

        $paths = [
            base_path($outputPath),
            storage_path('app/private/'.$outputPath),
            storage_path('app/'.$outputPath),
            $outputPath,
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return '';
    }

    private function callExecutor(object $executor, ImportApplyPlan $plan): mixed
    {
        foreach (['apply', 'execute', 'run', 'handle', 'realApply', 'applyReal', 'realApplyNow', 'executeRealApply'] as $method) {
            if (method_exists($executor, $method)) {
                return $executor->{$method}($plan);
            }
        }

        throw new \RuntimeException('No supported real apply method found in LdapRealApplyExecutor.');
    }

    private function resultLooksSuccessful(mixed $result, ImportApplyPlan $plan): bool
    {
        if (is_array($result)) {
            if (($result['ok'] ?? null) === true) {
                return true;
            }

            if (($result['success'] ?? null) === true) {
                return true;
            }

            if (strtolower((string) ($result['status'] ?? '')) === 'success') {
                return true;
            }
        }

        $status = strtolower((string) $plan->status);

        return in_array($status, [
            'success',
            'applied',
            'applied_verified',
            'applied_and_verified',
            'applied_verified_success',
            'applied & verified',
        ], true);
    }

    private function extractMessage(mixed $result, ImportApplyPlan $plan): ?string
    {
        if (is_array($result)) {
            return $result['message']
                ?? $result['error']
                ?? $result['error_message']
                ?? null;
        }

        return $plan->real_apply_error_message
            ?: $plan->apply_blocked_reason
            ?: $plan->message;
    }
}
