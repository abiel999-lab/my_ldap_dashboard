<?php

namespace App\Models\Directory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UniversalLdapEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'modify_timestamp' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }
}
