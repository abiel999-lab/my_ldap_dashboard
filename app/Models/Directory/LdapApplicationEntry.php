<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapApplicationEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_group_dns' => 'array',
            'required_role_ids' => 'array',
            'required_role_keys' => 'array',
            'resolved_user_ids' => 'array',
            'oidc_enabled' => 'boolean',
            'saml_enabled' => 'boolean',
            'api_access_enabled' => 'boolean',
            'object_classes' => 'array',
            'attributes' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapApplicationEntry $entry): void {
            if (blank($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }

            if (blank($entry->created_by)) {
                $entry->created_by = Auth::id();
            }

            if (blank($entry->updated_by)) {
                $entry->updated_by = Auth::id();
            }
        });

        static::updating(function (LdapApplicationEntry $entry): void {
            $entry->updated_by = Auth::id();
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function ldapGroupEntry(): BelongsTo
    {
        return $this->belongsTo(LdapGroupEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->app_name
            ?: $this->app_key
            ?: $this->cn
            ?: $this->dn
            ?: 'N/A';
    }

    public function getObjectClassesTextAttribute(): string
    {
        $classes = $this->object_classes ?? [];

        if ($classes === []) {
            return 'N/A';
        }

        return collect($classes)->implode(', ');
    }

    public function getAllowedGroupDnsTextAttribute(): string
    {
        $groups = $this->allowed_group_dns ?? [];

        if ($groups === []) {
            return 'No allowed group DNs cached.';
        }

        return collect($groups)
            ->map(fn ($dn): string => '- '.(string) $dn)
            ->implode(PHP_EOL);
    }

    public function getRequiredRoleKeysTextAttribute(): string
    {
        $roles = $this->required_role_keys ?? [];

        if ($roles === []) {
            return 'No required roles resolved.';
        }

        return collect($roles)
            ->map(fn ($role): string => '- '.(string) $role)
            ->implode(PHP_EOL);
    }

    public function getRequiredRoleIdsTextAttribute(): string
    {
        $ids = $this->required_role_ids ?? [];

        if ($ids === []) {
            return 'No required role IDs resolved.';
        }

        return collect($ids)
            ->map(fn ($id): string => '- Role ID: '.(string) $id)
            ->implode(PHP_EOL);
    }

    public function getResolvedUserIdsTextAttribute(): string
    {
        $ids = $this->resolved_user_ids ?? [];

        if ($ids === []) {
            return 'No resolved users.';
        }

        return collect($ids)
            ->map(fn ($id): string => '- User ID: '.(string) $id)
            ->implode(PHP_EOL);
    }

    public function getAttributesJsonAttribute(): string
    {
        return json_encode($this->attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getMetadataJsonAttribute(): string
    {
        return json_encode($this->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
