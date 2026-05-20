<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapGroupEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'operational_attributes' => 'array',
            'member_dns' => 'array',
            'member_uids' => 'array',
            'nested_group_dns' => 'array',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapGroupEntry $entry): void {
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

        static::updating(function (LdapGroupEntry $entry): void {
            $entry->updated_by = Auth::id();
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
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
        return $this->cn
            ?: $this->ou
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

    public function getMemberDnsTextAttribute(): string
    {
        $members = $this->member_dns ?? [];

        if ($members === []) {
            return 'No cached DN members.';
        }

        return collect($members)
            ->map(fn ($member): string => '- '.(string) $member)
            ->implode(PHP_EOL);
    }

    public function getMemberUidsTextAttribute(): string
    {
        $members = $this->member_uids ?? [];

        if ($members === []) {
            return 'No cached UID members.';
        }

        return collect($members)
            ->map(fn ($member): string => '- '.(string) $member)
            ->implode(PHP_EOL);
    }

    public function getNestedGroupDnsTextAttribute(): string
    {
        $groups = $this->nested_group_dns ?? [];

        if ($groups === []) {
            return 'No cached nested groups.';
        }

        return collect($groups)
            ->map(fn ($group): string => '- '.(string) $group)
            ->implode(PHP_EOL);
    }

    public function getAttributesJsonAttribute(): string
    {
        return json_encode($this->attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getOperationalAttributesJsonAttribute(): string
    {
        return json_encode($this->operational_attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
