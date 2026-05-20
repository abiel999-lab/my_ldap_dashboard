<?php

namespace App\Models\Directory;

use Illuminate\Database\Eloquent\Model;

class LdapSchemaEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'names' => 'array',
        'must_attributes' => 'array',
        'may_attributes' => 'array',
        'applies_to_attributes' => 'array',
        'is_single_value' => 'boolean',
        'is_operational' => 'boolean',
        'is_obsolete' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function ldapConnection()
    {
        return $this->belongsTo(LdapConnection::class, 'ldap_connection_id');
    }
}
