<?php

namespace App\Console\Commands;

use App\Models\Directory\LdapConnection;
use Illuminate\Console\Command;

class SeedDummyLdapConnectionCommand extends Command
{
    protected $signature = 'iam:seed-dummy-ldap-connection';

    protected $description = 'Create or update a safe dummy LDAP connection for UI testing.';

    public function handle(): int
    {
        $connection = LdapConnection::query()->updateOrCreate(
            [
                'name' => 'Local Test LDAP',
            ],
            [
                'environment_label' => 'local',
                'host' => '127.0.0.1',
                'port' => 389,
                'base_dn' => 'dc=petra,dc=ac,dc=id',
                'bind_dn' => null,
                'bind_password' => null,
                'use_ssl' => false,
                'use_tls' => false,
                'timeout' => 5,
                'is_active' => true,
                'is_default' => true,
                'is_read_only' => true,
                'user_base_dn' => null,
                'group_base_dn' => null,
                'user_identifier_attribute' => 'uid',
                'user_display_attribute' => 'cn',
                'user_email_attribute' => 'mail',
                'group_member_attribute' => 'member',
                'uuid_attribute' => 'entryUUID',
                'attribute_mapping' => [
                    'display_name' => 'cn',
                    'email' => 'mail',
                    'identifier' => 'uid',
                ],
                'metadata' => [
                    'purpose' => 'UI testing only',
                    'safe' => true,
                ],
                'last_health_checked_at' => null,
                'last_health_status' => null,
                'last_health_message' => null,
            ],
        );

        LdapConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->info('Dummy LDAP connection is ready: '.$connection->display_name);

        return self::SUCCESS;
    }
}
