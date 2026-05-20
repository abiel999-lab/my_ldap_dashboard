<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapSchemaEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_single_value' => 'boolean',
            'is_obsolete' => 'boolean',
            'is_operational' => 'boolean',
            'names' => 'array',
            'must_attributes' => 'array',
            'may_attributes' => 'array',
            'extensions' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapSchemaEntry $entry): void {
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

        static::updating(function (LdapSchemaEntry $entry): void {
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
        return $this->display_name
            ?: $this->name
            ?: $this->oid
            ?: 'N/A';
    }

    public function getNamesTextAttribute(): string
    {
        $items = $this->names ?? [];

        if ($items === []) {
            return 'N/A';
        }

        return collect($items)
            ->map(fn ($item): string => '- '.(string) $item)
            ->implode(PHP_EOL);
    }

    public function getMustAttributesTextAttribute(): string
    {
        $items = $this->must_attributes ?? [];

        if ($items === []) {
            return 'No MUST attributes.';
        }

        return collect($items)
            ->map(fn ($item): string => '- '.(string) $item)
            ->implode(PHP_EOL);
    }

    public function getMayAttributesTextAttribute(): string
    {
        $items = $this->may_attributes ?? [];

        if ($items === []) {
            return 'No MAY attributes.';
        }

        return collect($items)
            ->map(fn ($item): string => '- '.(string) $item)
            ->implode(PHP_EOL);
    }

    public function getExtensionsJsonAttribute(): string
    {
        return json_encode($this->extensions ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getMetadataJsonAttribute(): string
    {
        return json_encode($this->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
