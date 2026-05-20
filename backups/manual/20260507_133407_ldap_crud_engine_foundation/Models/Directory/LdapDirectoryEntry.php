<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapDirectoryEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'operational_attributes' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapDirectoryEntry $entry): void {
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

        static::updating(function (LdapDirectoryEntry $entry): void {
            $entry->updated_by = Auth::id();
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function entryTypeRule(): BelongsTo
    {
        return $this->belongsTo(LdapEntryTypeRule::class, 'ldap_entry_type_rule_id');
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
        return $this->display_value
            ?: $this->identifier_value
            ?: $this->rdn_value
            ?: $this->dn
            ?: 'N/A';
    }

    public function getObjectClassesTextAttribute(): string
    {
        $items = $this->object_classes ?? [];

        if ($items === []) {
            return 'No objectClass cached.';
        }

        return collect($items)
            ->map(fn ($item): string => '- '.(string) $item)
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

    public function getMetadataJsonAttribute(): string
    {
        return json_encode($this->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
