<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BulkLdapOperationItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'before_value' => 'array',
            'after_value' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BulkLdapOperationItem $item): void {
            if (blank($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function bulkLdapOperation(): BelongsTo
    {
        return $this->belongsTo(BulkLdapOperation::class);
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function commandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class);
    }

    public function operationJobItem(): BelongsTo
    {
        return $this->belongsTo(OperationJobItem::class);
    }
}
