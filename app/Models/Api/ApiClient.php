<?php

namespace App\Models\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'is_active',
        'expires_at',
        'last_used_at',
        'last_used_ip',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiClient $client): void {
            if (! $client->uuid) {
                $client->uuid = (string) Str::uuid();
            }
        });
    }

    public static function generatePlainKey(): string
    {
        return 'petra_live_'.Str::random(64);
    }

    public static function hashPlainKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public static function prefixFromPlainKey(string $plainKey): string
    {
        return substr($plainKey, 0, 18);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
