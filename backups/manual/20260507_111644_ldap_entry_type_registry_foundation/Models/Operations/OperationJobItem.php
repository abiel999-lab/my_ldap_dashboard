<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationJobItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function getDisplayTargetAttribute(): string
    {
        return (string) (
            $this->rawFirst(['target_identifier', 'target_key', 'target_dn'])
            ?? ('Operation Job Item #'.$this->getKey())
        );
    }

    public function getDisplayActionAttribute(): string
    {
        return (string) ($this->rawFirst(['action', 'operation_action']) ?? 'N/A');
    }

    public function getDisplayStatusAttribute(): string
    {
        return (string) ($this->rawFirst(['status']) ?? 'N/A');
    }

    public function getDisplayErrorMessageAttribute(): string
    {
        return (string) ($this->rawFirst(['error_message', 'last_error']) ?? 'No error');
    }

    private function rawFirst(array $keys): mixed
    {
        $attributes = $this->getAttributes();

        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes) && filled($attributes[$key])) {
                return $attributes[$key];
            }
        }

        return null;
    }
}
