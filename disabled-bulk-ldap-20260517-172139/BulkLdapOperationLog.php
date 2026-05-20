<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkLdapOperationLog extends Model
{
    protected $fillable = [
        'bulk_ldap_operation_id',
        'operation_name',
        'operation_type',
        'ldap_connection_name',
        'base_dn',
        'ldap_filter',
        'target_dn',
        'status',
        'reason',
        'payload',
        'result',
        'executed_by',
        'executed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'executed_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BulkLdapOperation::class, 'bulk_ldap_operation_id');
    }
}
