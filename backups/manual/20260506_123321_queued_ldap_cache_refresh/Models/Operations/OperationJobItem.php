<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationJobItem extends Model
{
    protected $fillable = [
        'uuid',
        'operation_job_id',
        'target_type',
        'target_identifier',
        'target_dn',
        'action',
        'status',
        'payload_hash',
        'input_payload',
        'output_payload',
        'error_message',
        'attempt_count',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OperationJobItem $item): void {
            if (blank($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->target_identifier
            ?? $this->target_dn
            ?? ('Operation Job Item #'.$this->getKey());
    }
}
