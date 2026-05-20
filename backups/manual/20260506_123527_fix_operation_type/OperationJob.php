<?php

namespace App\Models\Operations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OperationJob extends Model
{
    protected $fillable = [
        'uuid',
        'type',
        'name',
        'module',
        'action',
        'status',
        'source',
        'target_type',
        'target_key',
        'target_dn',
        'ldap_connection_id',
        'total_items',
        'processed_items',
        'success_items',
        'failed_items',
        'skipped_items',
        'conflict_items',
        'metadata',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'success_items' => 'integer',
            'failed_items' => 'integer',
            'skipped_items' => 'integer',
            'conflict_items' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OperationJob $job): void {
            if (blank($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OperationJobItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OperationJobLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getProgressPercentAttribute(): int
    {
        $total = (int) ($this->total_items ?? 0);
        $processed = (int) ($this->processed_items ?? 0);

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($processed / $total) * 100));
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name
            ?? $this->type
            ?? $this->action
            ?? ('Operation Job #'.$this->getKey());
    }
}
