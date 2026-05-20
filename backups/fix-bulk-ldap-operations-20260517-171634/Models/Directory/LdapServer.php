<?php

namespace App\Models\Directory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class LdapServer extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'organization',
        'domain',
        'base_dn',
        'admin_rdn',
        'admin_dn',
        'admin_password',
        'host',
        'ldap_port',
        'ldaps_port',
        'scheme',
        'provision_mode',
        'expose_mode',
        'container_name',
        'docker_image',
        'status',
        'is_active',
        'is_registered_connection',
        'last_error',
        'last_tested_at',
        'last_test_status',
        'docker_command',
        'docker_compose_yaml',
        'kubernetes_manifest',
        'metadata',
    ];

    protected $casts = [
        'ldap_port' => 'integer',
        'ldaps_port' => 'integer',
        'is_active' => 'boolean',
        'is_registered_connection' => 'boolean',
        'last_tested_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected function adminPassword(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value,
        );
    }

    public function endpoint(): string
    {
        $scheme = $this->scheme ?: 'ldap';
        $host = $this->host ?: '127.0.0.1';
        $port = $scheme === 'ldaps'
            ? ($this->ldaps_port ?: 636)
            : ($this->ldap_port ?: 389);

        return "{$scheme}://{$host}:{$port}";
    }

    public function safeName(): string
    {
        return Str::slug($this->slug ?: $this->name ?: 'ldap-server');
    }
}
