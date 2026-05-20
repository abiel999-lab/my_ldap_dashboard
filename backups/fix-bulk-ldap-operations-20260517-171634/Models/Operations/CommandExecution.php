<?php

namespace App\Models\Operations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommandExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'environment_context' => 'array',
            'safe_mode' => 'boolean',
            'preview_mode' => 'boolean',
            'destructive' => 'boolean',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommandExecution $execution): void {
            if (blank($execution->uuid)) {
                $execution->uuid = (string) Str::uuid();
            }

            if (blank($execution->module)) {
                $execution->module = 'operations.command';
            }

            if (blank($execution->command_type)) {
                $execution->command_type = 'safe_artisan';
            }

            if (blank($execution->status)) {
                $execution->status = 'pending';
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class, 'operation_job_id');
    }

    public function operationJobItem(): BelongsTo
    {
        return $this->belongsTo(OperationJobItem::class, 'operation_job_item_id');
    }

    public function getDisplayCommandAttribute(): string
    {
        return str((string) $this->command)
            ->limit(120)
            ->toString();
    }

    public function getDisplayOutputAttribute(): string
    {
        $stdout = trim((string) $this->stdout);
        $stderr = trim((string) $this->stderr);

        if ($stdout === '' && $stderr === '') {
            return 'No output.';
        }

        return trim($stdout."\n\n".$stderr);
    }
}
