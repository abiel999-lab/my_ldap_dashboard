<?php

namespace App\Models\Operations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OperationJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
        $total = (int) ($this->rawFirst(['total_items']) ?? 0);
        $processed = (int) ($this->rawFirst(['processed_items']) ?? 0);

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($processed / $total) * 100));
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) (
            $this->rawFirst(['name', 'title', 'operation_name'])
            ?? ('Operation Job #'.$this->getKey())
        );
    }

    public function getDisplayTypeAttribute(): string
    {
        return (string) ($this->rawFirst(['operation_type', 'type']) ?? 'N/A');
    }

    public function getDisplayActionAttribute(): string
    {
        return (string) ($this->rawFirst(['operation_action', 'action']) ?? 'N/A');
    }

    public function getDisplaySourceAttribute(): string
    {
        return (string) ($this->rawFirst(['source', 'triggered_by', 'origin']) ?? 'N/A');
    }

    public function getDisplayTargetTypeAttribute(): string
    {
        return (string) ($this->rawFirst(['target_type', 'operation_target_type']) ?? 'N/A');
    }

    public function getDisplayTargetKeyAttribute(): string
    {
        return (string) ($this->rawFirst(['target_key', 'target_identifier', 'operation_target_key']) ?? 'N/A');
    }

    public function getDisplayTargetDnAttribute(): string
    {
        return (string) ($this->rawFirst(['target_dn', 'dn', 'operation_target_dn']) ?? 'N/A');
    }

    public function getDisplayLdapConnectionIdAttribute(): string
    {
        $value = $this->rawFirst(['ldap_connection_id', 'connection_id']);

        return filled($value) ? (string) $value : 'N/A';
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
