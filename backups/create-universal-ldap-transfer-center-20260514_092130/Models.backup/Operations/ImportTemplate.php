<?php

namespace App\Models\Operations;

use App\Models\Directory\LdapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportTemplate extends Model
{
    protected $fillable = [
        'ldap_connection_id',
        'name',
        'template_purpose',
        'entry_type',
        'file_format',
        'base_dn',
        'target_ou',
        'rdn_attribute',
        'object_classes',
        'attributes',
        'sample_values',
        'multi_value_separator',
        'sample_rows',
        'output_disk',
        'output_path',
        'output_filename',
        'output_size_bytes',
        'output_hash',
        'status',
        'message',
        'metadata',
    ];

    protected $casts = [
        'object_classes' => 'array',
        'attributes' => 'array',
        'sample_values' => 'array',
        'metadata' => 'array',
        'sample_rows' => 'integer',
        'output_size_bytes' => 'integer',
    ];

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class);
    }

    public function getDownloadUrlAttribute(): ?string
    {
        if (! $this->output_path) {
            return null;
        }

        return route('operations.import-templates.download', $this);
    }
}
