<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LdapTransferBatch extends Model
{
    protected $table = 'ldap_transfer_batches';

    protected $guarded = [];

    protected $casts = [
        'include_operational_attributes' => 'boolean',
        'delete_source_after_copy' => 'boolean',
        'excluded_attributes' => 'array',
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class, 'source_ldap_connection_id');
    }

    public function targetConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class, 'target_ldap_connection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LdapTransferItem::class, 'ldap_transfer_batch_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: 'LDAP Transfer #'.$this->id;
    }
}
