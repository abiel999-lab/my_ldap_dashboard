<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportApplyPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'safe_mode' => 'boolean',
            'dry_run' => 'boolean',
            'destructive' => 'boolean',
            'total_rows' => 'integer',
            'planned_create_rows' => 'integer',
            'planned_update_rows' => 'integer',
            'skipped_rows' => 'integer',
            'failed_rows' => 'integer',
            'output_size_bytes' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'dry_run_verified_at' => 'datetime',
            'real_apply_started_at' => 'datetime',
            'real_apply_finished_at' => 'datetime',
            'post_apply_verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ImportApplyPlan $plan): void {
            if (blank($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }

            if (blank($plan->created_by)) {
                $plan->created_by = Auth::id();
            }

            if (blank($plan->updated_by)) {
                $plan->updated_by = Auth::id();
            }

            if (blank($plan->approval_status)) {
                $plan->approval_status = 'not_requested';
            }

            $plan->safe_mode = true;
            $plan->dry_run = true;
            $plan->destructive = false;
        });

        static::updating(function (ImportApplyPlan $plan): void {
            $plan->updated_by = Auth::id();
        });
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class);
    }

    public function dryRunCommandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class, 'dry_run_command_execution_id');
    }

    public function realApplyCommandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class, 'real_apply_command_execution_id');
    }

    public function postApplyCommandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class, 'post_apply_command_execution_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function dryRunVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dry_run_verified_by');
    }

    public function realApplyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'real_apply_by');
    }

    public function postApplyVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'post_apply_verified_by');
    }

    public function hasOutputFile(): bool
    {
        return filled($this->output_path) && Storage::disk($this->output_disk ?: 'local')->exists((string) $this->output_path);
    }

    public function outputAbsolutePath(): ?string
    {
        if (! $this->hasOutputFile()) {
            return null;
        }

        return Storage::disk($this->output_disk ?: 'local')->path((string) $this->output_path);
    }

    public function outputFilename(): string
    {
        if (blank($this->output_path)) {
            return 'import-apply-plan-'.$this->id.'.ldif';
        }

        return basename((string) $this->output_path);
    }

    public function getDisplayOutputSizeAttribute(): string
    {
        $bytes = (int) ($this->output_size_bytes ?? 0);

        if ($bytes <= 0) {
            return 'N/A';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' MB';
        }

        return round($bytes / 1024, 2).' KB';
    }

    public function readOutputContent(int $maxBytes = 200000): string
    {
        if (! $this->hasOutputFile()) {
            return 'Apply plan file is missing.';
        }

        $content = Storage::disk($this->output_disk ?: 'local')->get((string) $this->output_path);

        if (strlen($content) > $maxBytes) {
            return substr($content, 0, $maxBytes)."\n\n--- FILE TRUNCATED IN UI. DOWNLOAD FULL FILE TO VIEW ALL CONTENT. ---";
        }

        return $content;
    }

    public function canRequestApproval(): bool
    {
        return $this->status === 'success'
            && $this->hasOutputFile()
            && in_array($this->approval_status, ['not_requested', 'rejected'], true);
    }

    public function canApprove(): bool
    {
        return $this->status === 'success'
            && $this->hasOutputFile()
            && in_array($this->approval_status, ['not_requested', 'pending', 'rejected'], true);
    }

    public function canReject(): bool
    {
        return in_array($this->approval_status, ['pending', 'approved'], true)
            && ! in_array($this->status, ['applied', 'apply_running'], true);
    }

    public function canVerifyDryRun(): bool
    {
        return $this->status === 'success'
            && $this->approval_status === 'approved'
            && $this->safe_mode
            && $this->dry_run
            && ! $this->destructive
            && $this->hasOutputFile();
    }

    public function canApplyLater(): bool
    {
        return $this->status === 'dry_run_verified'
            && $this->approval_status === 'approved'
            && $this->dry_run_verified_at !== null
            && $this->safe_mode
            && $this->dry_run
            && ! $this->destructive
            && $this->hasOutputFile()
            && $this->real_apply_finished_at === null;
    }

    public function canRealApply(): bool
    {
        return $this->canApplyLater();
    }

    public function canVerifyPostApply(): bool
    {
        return $this->status === 'applied'
            && $this->approval_status === 'approved'
            && $this->real_apply_finished_at !== null
            && $this->hasOutputFile();
    }
}
