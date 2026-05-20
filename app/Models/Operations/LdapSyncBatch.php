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
            $batch->attributes ??= '*';
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
                $baseDn = $this->safeString($this->getRawOriginal('base_dn'));
                $scope = $this->safeString($this->getRawOriginal('sync_scope') ?: 'full');

                if ($scope === 'custom_dn') {
                    return $this->safeString($this->getRawOriginal('custom_target_dn'));
                }

                if ($scope === 'full') {
                    return $baseDn;
                }

                if (in_array($scope, ['ou', 'cn', 'uid'], true)) {
                    $attr = $this->safeString($this->getRawOriginal('target_rdn_attribute') ?: $scope);
                    $value = $this->safeString($this->getRawOriginal('target_rdn_value'));

                    if ($attr === '' || $value === '' || $baseDn === '') {
                        return '';
                    }

                    if (str_contains($value, '=')) {
                        return $value.','.$baseDn;
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
            get: fn (): string => $this->effective_base_dn ?: $this->safeString($this->getRawOriginal('base_dn'))
        );
    }

    protected function attributeList(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $value = $this->getRawOriginal('attributes');

                if ($value === null) {
                    return ['*'];
                }

                if (is_array($value)) {
                    return $this->normalizeList($value);
                }

                if (! is_string($value)) {
                    $value = $this->safeString($value);
                }

                $value = trim($value);

                if ($value === '') {
                    return ['*'];
                }

                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $this->normalizeList($decoded);
                }

                $items = preg_split('/[\s,]+/', $value) ?: [];

                $normalized = $this->normalizeList($items);

                return $normalized === [] ? ['*'] : $normalized;
            }
        );
    }

    private function safeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            return trim(implode(',', $this->normalizeList($value)));
        }

        return '';
    }

    private function normalizeList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatten()
            ->map(function ($item): string {
                if (is_array($item)) {
                    return implode(',', $this->normalizeList($item));
                }

                if ($item === null) {
                    return '';
                }

                if (is_bool($item)) {
                    return $item ? '1' : '0';
                }

                if (is_scalar($item)) {
                    return trim((string) $item);
                }

                return '';
            })
            ->flatMap(fn (string $item): array => preg_split('/[\s,]+/', $item) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
