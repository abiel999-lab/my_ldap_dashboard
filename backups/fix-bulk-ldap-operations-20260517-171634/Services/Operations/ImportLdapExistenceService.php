<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use Symfony\Component\Process\Process;
use Throwable;

class ImportLdapExistenceService
{
    public function dnExists(?LdapConnection $connection, ?string $dn): bool
    {
        if (! $connection || blank($dn)) {
            return false;
        }

        $command = [
            'ldapsearch',
            '-LLL',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-b',
            trim((string) $dn),
            '-s',
            'base',
            '(objectClass=*)',
            'dn',
        ];

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful() && str_contains($process->getOutput(), 'dn: ');
        } catch (Throwable) {
            return false;
        }
    }
}
