<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationJobLog extends Model
{
    protected $fillable = [
        'uuid',
        'operation_job_id',
        'operation_job_item_id',
        'level',
        'message',
        'context',
        'created_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OperationJobLog $log): void {
            if (blank($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }

            if (blank($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class);
    }

    public function operationJobItem(): BelongsTo
    {
        return $this->belongsTo(OperationJobItem::class);
    }
}
