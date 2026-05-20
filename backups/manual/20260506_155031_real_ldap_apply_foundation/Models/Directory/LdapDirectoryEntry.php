<?php

namespace App\Models\Directory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LdapDirectoryEntry extends Model
{
    protected $fillable = [
        'uuid',
        'ldap_connection_id',
        'connection_name',
        'base_dn',
        'entry_dn',
        'parent_dn',
        'entry_rdn',
        'entry_type',
        'object_classes',
        'attributes',
        'operational_attributes',
        'depth',
        'source_hash',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'operational_attributes' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapDirectoryEntry $entry): void {
            if (blank($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class, 'ldap_connection_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->entry_rdn ?: $this->entry_dn;
    }
}
