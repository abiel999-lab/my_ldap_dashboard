<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class BulkLdapOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_object_classes' => 'array',
            'default_attributes' => 'array',
            'metadata' => 'array',
            'safe_mode' => 'boolean',
            'dry_run' => 'boolean',
            'destructive' => 'boolean',
            'approval_required' => 'boolean',
            'previewed_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BulkLdapOperation $operation): void {
            if (blank($operation->uuid)) {
                $operation->uuid = (string) Str::uuid();
            }

            if (blank($operation->created_by)) {
                $operation->created_by = Auth::id();
            }

            if (blank($operation->updated_by)) {
                $operation->updated_by = Auth::id();
            }
        });

        static::updating(function (BulkLdapOperation $operation): void {
            $operation->updated_by = Auth::id();
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkLdapOperationItem::class);
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getCounterSummaryAttribute(): string
    {
        return 'total='.$this->total_items
            .' pending='.$this->pending_items
            .' success='.$this->success_items
            .' failed='.$this->failed_items
            .' already='.$this->already_applied_items
            .' conflict='.$this->conflict_items;
    }
}
