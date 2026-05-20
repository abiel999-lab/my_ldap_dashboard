<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HealthCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'checked_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HealthCheck $healthCheck): void {
            if (blank($healthCheck->uuid)) {
                $healthCheck->uuid = (string) Str::uuid();
            }
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->component.' / '.$this->name;
    }
}
