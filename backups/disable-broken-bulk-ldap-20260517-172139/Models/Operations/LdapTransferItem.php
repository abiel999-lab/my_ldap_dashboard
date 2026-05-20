<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LdapTransferItem extends Model
{
    protected $table = 'ldap_transfer_items';

    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LdapTransferBatch::class, 'ldap_transfer_batch_id');
    }
}
