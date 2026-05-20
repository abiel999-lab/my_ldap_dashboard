<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

class FailedQueueJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    public function getPayloadPreviewAttribute(): string
    {
        $payload = json_decode((string) $this->payload, true);

        if (! is_array($payload)) {
            return 'Invalid payload';
        }

        return (string) ($payload['displayName'] ?? $payload['job'] ?? 'Failed job');
    }

    public function getExceptionPreviewAttribute(): string
    {
        return str($this->exception ?? '')
            ->limit(180)
            ->toString();
    }
}
