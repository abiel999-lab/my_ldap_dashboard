<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkLdapOperationLog extends Model
{
    protected $fillable = [
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
}
