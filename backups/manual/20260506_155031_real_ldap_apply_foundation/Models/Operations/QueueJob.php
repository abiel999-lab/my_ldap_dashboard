<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

class QueueJob extends Model
{
    protected $table = 'jobs';

    public $timestamps = false;

    protected $fillable = [
        'queue',
        'payload',
        'attempts',
        'reserved_at',
        'available_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'reserved_at' => 'integer',
            'available_at' => 'integer',
            'created_at' => 'integer',
        ];
    }

    public function getCreatedAtHumanAttribute(): ?string
    {
        return $this->created_at ? date('Y-m-d H:i:s', (int) $this->created_at) : null;
    }

    public function getAvailableAtHumanAttribute(): ?string
    {
        return $this->available_at ? date('Y-m-d H:i:s', (int) $this->available_at) : null;
    }

    public function getReservedAtHumanAttribute(): ?string
    {
        return $this->reserved_at ? date('Y-m-d H:i:s', (int) $this->reserved_at) : null;
    }

    public function getPayloadPreviewAttribute(): string
    {
        $payload = json_decode((string) $this->payload, true);

        if (! is_array($payload)) {
            return 'Invalid payload';
        }

        return (string) ($payload['displayName'] ?? $payload['job'] ?? 'Queued job');
    }
}
