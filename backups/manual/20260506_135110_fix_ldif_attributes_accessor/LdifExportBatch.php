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

        return round($bytes / 1024, 2).' KB';
    }

    public function getAttributeListAttribute(): array
    {
        $attributes = trim((string) $this->attributes);

        if ($attributes === '') {
            return ['*'];
        }

        return collect(preg_split('/[\s,]+/', $attributes))
            ->filter()
            ->values()
            ->all();
    }
}
