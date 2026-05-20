<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LdapCrudOperation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'preview_result' => 'array',
        'execution_result' => 'array',
        'skip_if_invalid' => 'boolean',
        'require_preview' => 'boolean',
        'delete_related_objectclass_attributes' => 'boolean',
        'previewed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class, 'ldap_connection_id');
    }
}
