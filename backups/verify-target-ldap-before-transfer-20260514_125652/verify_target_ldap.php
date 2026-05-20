<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Directory\LdapConnection;

$target = LdapConnection::query()
    ->where('is_active', true)
    ->where('name', 'not ilike', '%petra%')
    ->orderBy('name')
    ->first();

if (! $target) {
    echo "NO_TARGET_FOUND\n";
    exit(1);
}

$data = [
    'id' => $target->id,
    'name' => $target->name,
    'host' => $target->host,
    'port' => $target->port,
    'base_dn' => $target->base_dn,
    'bind_dn' => $target->bind_dn,
    'bind_password' => $target->bind_password,
    'uri' => ((bool) ($target->use_ssl ?? false) ? 'ldaps' : 'ldap').'://'.$target->host.':'.$target->port,
];

file_put_contents(__DIR__.'/target_ldap.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "TARGET_ID=".$data['id']."\n";
echo "TARGET_NAME=".$data['name']."\n";
echo "TARGET_URI=".$data['uri']."\n";
echo "TARGET_BASE_DN=".$data['base_dn']."\n";
echo "TARGET_BIND_DN=".$data['bind_dn']."\n";
