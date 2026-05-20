<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapSyncBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'safe_mode' => 'boolean',
            'preview_mode' => 'boolean',
            'destructive' => 'boolean',
            'size_limit' => 'integer',
            'page_size' => 'integer',
            'total_entries' => 'integer',
            'created_entries' => 'integer',
            'updated_entries' => 'integer',
            'failed_entries' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapSyncBatch $batch): void {
            $batch->uuid ??= (string) Str::uuid();
            $batch->created_by ??= Auth::id();
            $batch->updated_by ??= Auth::id();
            $batch->status ??= 'draft';
            $batch->sync_scope ??= 'full';
            $batch->search_scope ??= 'sub';
            $batch->filter ??= '(objectClass=*)';
            $batch->size_limit ??= 5000;
            $batch->page_size ??= 1000;
            $batch->safe_mode = true;
            $batch->destructive = false;
        });

        static::updating(function (LdapSyncBatch $batch): void {
            $batch->updated_by = Auth::id();
            $batch->safe_mode = true;
            $batch->destructive = false;
        });
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function effectiveBaseDn(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $baseDn = trim((string) ($this->base_dn ?? ''));
                $scope = trim((string) ($this->sync_scope ?: 'full'));

                if ($scope === 'custom_dn') {
                    return trim((string) ($this->custom_target_dn ?? ''));
                }

                if ($scope === 'full') {
                    return $baseDn;
                }

                if (in_array($scope, ['ou', 'cn', 'uid'], true)) {
                    $attr = trim((string) ($this->target_rdn_attribute ?: $scope));
                    $value = trim((string) ($this->target_rdn_value ?? ''));

                    if ($attr === '' || $value === '' || $baseDn === '') {
                        return '';
                    }

                    if (str_contains($value, '=')) {
                        return $attr.'='.$value.','.$baseDn;
                    }

                    return $attr.'='.$value.','.$baseDn;
                }

                return $baseDn;
            }
        );
    }

    protected function displayTarget(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->effective_base_dn ?: (string) ($this->base_dn ?? '')
        );
    }

    protected function attributeList(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $value = trim((string) ($this->attributes ?? ''));

                if ($value === '') {
                    return ['*'];
                }

                return collect(preg_split('/[\s,]+/', $value))
                    ->map(fn ($item): string => trim((string) $item))
                    ->filter()
                    ->values()
                    ->all();
            }
        );
    }
}
