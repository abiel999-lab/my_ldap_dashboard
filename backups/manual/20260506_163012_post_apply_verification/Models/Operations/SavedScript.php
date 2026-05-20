<?php

namespace App\Models\Operations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SavedScript extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_parameters' => 'array',
            'safe_mode_required' => 'boolean',
            'preview_only' => 'boolean',
            'destructive' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SavedScript $script): void {
            if (blank($script->uuid)) {
                $script->uuid = (string) Str::uuid();
            }

            if (blank($script->created_by)) {
                $script->created_by = Auth::id();
            }

            if (blank($script->updated_by)) {
                $script->updated_by = Auth::id();
            }
        });

        static::updating(function (SavedScript $script): void {
            $script->updated_by = Auth::id();
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

    public function getDisplayScriptAttribute(): string
    {
        return str((string) $this->script_body)
            ->limit(120)
            ->toString();
    }
}
