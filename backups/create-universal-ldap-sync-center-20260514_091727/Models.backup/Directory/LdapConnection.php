<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapConnection extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'environment_label',
        'host',
        'port',
        'base_dn',
        'bind_dn',
        'bind_password',
        'use_ssl',
        'use_tls',
        'timeout',
        'is_active',
        'is_default',
        'is_read_only',
        'user_base_dn',
        'group_base_dn',
        'user_identifier_attribute',
        'user_display_attribute',
        'user_email_attribute',
        'group_member_attribute',
        'uuid_attribute',
        'attribute_mapping',
        'metadata',
        'last_health_checked_at',
        'last_health_status',
        'last_health_message',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'bind_password',
    ];

    protected function casts(): array
    {
        return [
            'bind_password' => 'encrypted',
            'use_ssl' => 'boolean',
            'use_tls' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_read_only' => 'boolean',
            'attribute_mapping' => 'array',
            'metadata' => 'array',
            'last_health_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapConnection $connection): void {
            if (blank($connection->uuid)) {
                $connection->uuid = (string) Str::uuid();
            }

            if (Auth::check()) {
                $connection->created_by ??= Auth::id();
                $connection->updated_by ??= Auth::id();
            }
        });

        static::updating(function (LdapConnection $connection): void {
            if (Auth::check()) {
                $connection->updated_by = Auth::id();
            }
        });

        static::saved(function (LdapConnection $connection): void {
            if (! $connection->is_default) {
                return;
            }

            static::query()
                ->whereKeyNot($connection->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return sprintf('%s (%s:%s)', $this->name, $this->host, $this->port);
    }

    public function getSecurityModeAttribute(): string
    {
        if ($this->use_ssl) {
            return 'SSL';
        }

        if ($this->use_tls) {
            return 'TLS';
        }

        return 'Plain';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
