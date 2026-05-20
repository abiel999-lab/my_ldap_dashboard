<?php

namespace Database\Seeders;

use App\Models\Directory\LdapConnection;
use Illuminate\Database\Seeder;

class LdapConnectionSeeder extends Seeder
{
    public function run(): void
    {
        LdapConnection::query()->updateOrCreate(
            ['name' => 'Petra LDAP Local Default'],
            [
                'environment_label' => 'local',
                'host' => env('LDAP_DEFAULT_HOST', '127.0.0.1'),
                'port' => (int) env('LDAP_DEFAULT_PORT', 389),
                'base_dn' => env('LDAP_DEFAULT_BASE_DN', 'dc=example,dc=org'),
                'bind_dn' => env('LDAP_DEFAULT_BIND_DN'),
                'bind_password' => env('LDAP_DEFAULT_BIND_PASSWORD'),
                'use_ssl' => filter_var(env('LDAP_DEFAULT_SSL', false), FILTER_VALIDATE_BOOLEAN),
                'use_tls' => filter_var(env('LDAP_DEFAULT_TLS', false), FILTER_VALIDATE_BOOLEAN),
                'timeout' => (int) env('LDAP_DEFAULT_TIMEOUT', 5),
                'is_active' => true,
                'is_default' => true,
                'is_read_only' => false,
                'user_base_dn' => null,
                'group_base_dn' => null,
                'user_identifier_attribute' => 'uid',
                'user_display_attribute' => 'cn',
                'user_email_attribute' => 'mail',
                'group_member_attribute' => 'member',
                'uuid_attribute' => 'entryUUID',
                'attribute_mapping' => [
                    'identifier' => 'uid',
                    'display_name' => 'cn',
                    'email' => 'mail',
                    'uuid' => 'entryUUID',
                    'group_member' => 'member',
                ],
                'metadata' => [
                    'source' => 'env_seed',
                    'notes' => 'Default local LDAP connection seeded from .env',
                ],
            ],
        );
    }
}
