<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'mapped_payload' => 'array',
            'validation_errors' => 'array',
            'warnings' => 'array',
            'row_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ImportRow $row): void {
            if (blank($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function getDisplayValidationErrorsAttribute(): string
    {
        $errors = $this->validation_errors ?? [];

        if ($errors === []) {
            return 'No validation errors.';
        }

        return collect($errors)
            ->map(fn ($error): string => '- '.(string) $error)
            ->implode(PHP_EOL);
    }

    public function getDisplayWarningsAttribute(): string
    {
        $warnings = $this->warnings ?? [];

        if ($warnings === []) {
            return 'No warnings.';
        }

        return collect($warnings)
            ->map(fn ($warning): string => '- '.(string) $warning)
            ->implode(PHP_EOL);
    }

    public function getRawPayloadJsonAttribute(): string
    {
        return json_encode($this->raw_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getMappedPayloadJsonAttribute(): string
    {
        return json_encode($this->mapped_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
