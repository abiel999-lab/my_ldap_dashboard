<?php

namespace App\Models\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiRequestLog extends Model
{
    protected $fillable = [
        'uuid',
        'api_client_id',
        'api_client_name',
        'method',
        'path',
        'scope',
        'ip',
        'user_agent',
        'status_code',
        'ok',
        'request_query',
        'response_summary',
        'error_message',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'request_query' => 'array',
            'response_summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiRequestLog $log): void {
            if (! $log->uuid) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }
}
