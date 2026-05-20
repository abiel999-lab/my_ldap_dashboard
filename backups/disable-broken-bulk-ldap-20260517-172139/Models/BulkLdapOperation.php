<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkLdapOperation extends Model
{
    protected $fillable = [
        'operation_name',
        'ldap_connection_name',
        'base_dn',
        'search_scope',
        'ldap_filter',
        'size_limit',
        'operation_type',
        'objectclass_name',
        'attribute_name',
        'attribute_value',
        'target_ou_dn',
        'existing_value_behavior',
        'status',
        'preview_result',
        'execution_result',
        'created_by',
        'previewed_at',
        'executed_at',
    ];

    protected $casts = [
        'preview_result' => 'array',
        'execution_result' => 'array',
        'previewed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(BulkLdapOperationLog::class);
    }
}
