<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
         * In Eloquent, $attributes is the internal model array.
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

    public function hasOutputFile(): bool
    {
        return filled($this->output_path) && Storage::disk('local')->exists((string) $this->output_path);
    }

    public function outputAbsolutePath(): ?string
    {
        if (! $this->hasOutputFile()) {
            return null;
        }

        return Storage::disk('local')->path((string) $this->output_path);
    }

    public function outputFilename(): string
    {
        if (blank($this->output_path)) {
            return 'ldif-export-'.$this->id.'.ldif';
        }

        return basename((string) $this->output_path);
    }

    public function readOutputContent(int $maxBytes = 200000): string
    {
        if (! $this->hasOutputFile()) {
            return 'LDIF output file is missing.';
        }

        $content = Storage::disk('local')->get((string) $this->output_path);

        if (strlen($content) > $maxBytes) {
            return substr($content, 0, $maxBytes)."\n\n--- FILE TRUNCATED IN UI. DOWNLOAD FULL FILE TO VIEW ALL CONTENT. ---";
        }

        return $content;
    }
}
