<?php

namespace App\Models\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LdapEntryTypeRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_object_classes' => 'array',
            'optional_object_classes' => 'array',
            'dn_contains_patterns' => 'array',
            'dn_starts_with_patterns' => 'array',
            'rdn_attributes' => 'array',
            'metadata' => 'array',
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LdapEntryTypeRule $rule): void {
            if (blank($rule->uuid)) {
                $rule->uuid = (string) Str::uuid();
            }

            if (blank($rule->created_by)) {
                $rule->created_by = Auth::id();
            }

            if (blank($rule->updated_by)) {
                $rule->updated_by = Auth::id();
            }
        });

        static::updating(function (LdapEntryTypeRule $rule): void {
            $rule->updated_by = Auth::id();
        });
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
        return $this->name ?: $this->rule_key ?: $this->entry_type ?: 'N/A';
    }

    public function getRequiredObjectClassesTextAttribute(): string
    {
        return $this->arrayToText($this->required_object_classes ?? [], 'No required objectClass.');
    }

    public function getOptionalObjectClassesTextAttribute(): string
    {
        return $this->arrayToText($this->optional_object_classes ?? [], 'No optional objectClass.');
    }

    public function getDnContainsPatternsTextAttribute(): string
    {
        return $this->arrayToText($this->dn_contains_patterns ?? [], 'No DN contains patterns.');
    }

    public function getDnStartsWithPatternsTextAttribute(): string
    {
        return $this->arrayToText($this->dn_starts_with_patterns ?? [], 'No DN starts-with patterns.');
    }

    public function getRdnAttributesTextAttribute(): string
    {
        return $this->arrayToText($this->rdn_attributes ?? [], 'No RDN attributes.');
    }

    public function getMetadataJsonAttribute(): string
    {
        return json_encode($this->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function arrayToText(array $items, string $empty): string
    {
        if ($items === []) {
            return $empty;
        }

        return collect($items)
            ->map(fn ($item): string => '- '.(string) $item)
            ->implode(PHP_EOL);
    }
}
