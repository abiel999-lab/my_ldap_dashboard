<?php

namespace App\Models\Audit;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_ip',
        'user_agent',
        'module',
        'action',
        'status',
        'target_type',
        'target_key',
        'ldap_connection_id',
        'target_dn',
        'request_payload',
        'before_value',
        'after_value',
        'command',
        'stdout',
        'stderr',
        'exit_code',
        'error_message',
        'operation_job_id',
        'operation_job_item_id',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'before_value' => 'array',
            'after_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $auditLog): void {
            if (blank($auditLog->uuid)) {
                $auditLog->uuid = (string) Str::uuid();
            }

            if (blank($auditLog->created_at)) {
                $auditLog->created_at = now();
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function ldapConnection(): BelongsTo
    {
        return $this->belongsTo(LdapConnection::class, 'ldap_connection_id');
    }

    public function operationJob(): BelongsTo
    {
        return $this->belongsTo(OperationJob::class, 'operation_job_id');
    }

    public function operationJobItem(): BelongsTo
    {
        return $this->belongsTo(OperationJobItem::class, 'operation_job_item_id');
    }
}
