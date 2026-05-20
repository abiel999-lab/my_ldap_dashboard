<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdifExportBatch extends Model
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
            'output_size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdifExportBatch $batch): void {
            if (blank($batch->uuid)) {
                $batch->uuid = (string) Str::uuid();
            }

            if (blank($batch->created_by)) {
                $batch->created_by = Auth::id();
            }

            if (blank($batch->updated_by)) {
                $batch->updated_by = Auth::id();
            }

            if (blank($batch->safe_mode)) {
                $batch->safe_mode = true;
            }

            if (blank($batch->preview_mode)) {
                $batch->preview_mode = false;
            }

            if (blank($batch->destructive)) {
                $batch->destructive = false;
            }
        });

        static::updating(function (LdifExportBatch $batch): void {
            $batch->updated_by = Auth::id();
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

    public function commandExecution(): BelongsTo
    {
        return $this->belongsTo(CommandExecution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDisplayOutputSizeAttribute(): string
    {
        $bytes = (int) ($this->output_size_bytes ?? 0);

        if ($bytes <= 0) {
            return 'N/A';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' MB';
        }

        return round($bytes / 1024, 2).' KB';
    }

    public function getAttributeListAttribute(): array
    {
        /*
         * IMPORTANT:
         * Do not use $this->attributes here.
         * In Eloquent, $attributes is the internal model array, so using
         * $this->attributes can cause "Array to string conversion".
         */
        $rawAttributes = $this->getRawOriginal('attributes');

        if (is_array($rawAttributes)) {
            return collect($rawAttributes)
                ->flatten()
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->values()
                ->all();
        }

        $attributeString = trim((string) $rawAttributes);

        if ($attributeString === '') {
            return ['*'];
        }

        return collect(preg_split('/[\s,]+/', $attributeString))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }
}
