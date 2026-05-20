<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapCrudOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'object_classes' => 'array',
            'attributes' => 'array',
            'attribute_changes' => 'array',
            'validation_errors' => 'array',
            'metadata' => 'array',
            'safe_mode' => 'boolean',
            'dry_run' => 'boolean',
            'destructive' => 'boolean',
            'approval_required' => 'boolean',
            'previewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapCrudOperation $operation): void {
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

        static::updating(function (LdapCrudOperation $operation): void {
            $operation->updated_by = Auth::id();
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function previewCommandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class, 'preview_command_execution_id');
    }

    public function applyCommandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class, 'apply_command_execution_id');
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

    public function getDisplayLabelAttribute(): string
    {
        return $this->name ?: '#'.$this->id.' '.$this->operation_type;
    }

    public function getAttributesJsonAttribute(): string
    {
        return json_encode($this->attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getAttributeChangesJsonAttribute(): string
    {
        return json_encode($this->attribute_changes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getValidationErrorsJsonAttribute(): string
    {
        return json_encode($this->validation_errors ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getMetadataJsonAttribute(): string
    {
        return json_encode($this->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
