<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class SyncDirectoryObjectsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public ?int $ldapConnectionId = null,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        $summary = [
            'connections' => [],
            'seen' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            $connections = $this->connections();

            foreach ($connections as $connection) {
                $result = $this->syncConnection($connection);
                $summary['connections'][] = $result;
                $summary['seen'] += $result['seen'];
                $summary['created'] += $result['created'];
                $summary['updated'] += $result['updated'];
                $summary['failed'] += $result['failed'];
                $summary['errors'] = array_merge($summary['errors'], $result['errors']);
            }

            if ($summary['failed'] > 0) {
                SafeCommandExecutionLogger::markFailed(
                    $this->commandExecutionId,
                    'Directory object sync completed with some errors.',
                    $summary
                );

                return;
            }

            SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $summary, $summary);
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed(
                $this->commandExecutionId,
                $e->getMessage(),
                $summary
            );

            throw $e;
        }
    }

    private function connections()
    {
        $query = LdapConnection::query();

        if ($this->ldapConnectionId) {
            return $query->whereKey($this->ldapConnectionId)->get();
        }

        $columns = Schema::getColumnListing((new LdapConnection())->getTable());

        foreach (['active', 'is_active', 'enabled', 'is_enabled'] as $column) {
            if (in_array($column, $columns, true)) {
                return $query->where($column, true)->orderBy('id')->get();
            }
        }

        return $query->orderBy('id')->get();
    }

    private function syncConnection(LdapConnection $connection): array
    {
        $result = [
            'connection_id' => $connection->id,
            'connection_name' => $connection->name ?? $connection->id,
            'seen' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $baseDn = $this->baseDn($connection);

        $command = [
            'ldapsearch',
            '-LLL',
            '-x',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($connection),
            '-D',
            $this->bindDn($connection),
            '-w',
            $this->bindPassword($connection),
            '-b',
            $baseDn,
            '(objectClass=*)',
            '*',
            '+',
        ];

        $process = new Process($command, base_path());
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful()) {
            $result['failed']++;
            $result['errors'][] = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'ldapsearch failed.');

            return $result;
        }

        $entries = $this->parseLdif($process->getOutput());
        $table = (new LdapDirectoryEntry())->getTable();
        $columns = Schema::getColumnListing($table);

        foreach ($entries as $entry) {
            $dn = $entry['dn'][0] ?? null;

            if (! $dn) {
                continue;
            }

            $result['seen']++;

            $objectClasses = $entry['objectClass'] ?? $entry['objectclass'] ?? [];
            $rdn = explode(',', $dn, 2)[0] ?? $dn;

            $payload = [];

            $this->setIfColumn($payload, $columns, 'ldap_connection_id', $connection->id);
            $this->setIfColumn($payload, $columns, 'connection_id', $connection->id);
            $this->setIfColumn($payload, $columns, 'dn', $dn);
            $this->setIfColumn($payload, $columns, 'rdn', $rdn);
            $this->setIfColumn($payload, $columns, 'object_classes', array_values($objectClasses));
            $this->setIfColumn($payload, $columns, 'attributes', $entry);
            $this->setIfColumn($payload, $columns, 'raw_attributes', $entry);
            $this->setIfColumn($payload, $columns, 'normal_attributes', $entry);
            $this->setIfColumn($payload, $columns, 'status', 'active');
            $this->setIfColumn($payload, $columns, 'last_seen_at', now());

            foreach (['uid', 'cn', 'sn', 'mail', 'ou'] as $attr) {
                if (in_array($attr, $columns, true) && isset($entry[$attr][0])) {
                    $payload[$attr] = $entry[$attr][0];
                }
            }

            $existing = LdapDirectoryEntry::query()
                ->where('dn', $dn)
                ->when(in_array('ldap_connection_id', $columns, true), fn ($q) => $q->where('ldap_connection_id', $connection->id))
                ->first();

            if ($existing) {
                $existing->forceFill($payload)->save();
                $result['updated']++;
            } else {
                LdapDirectoryEntry::query()->create($payload);
                $result['created']++;
            }
        }

        return $result;
    }

    private function setIfColumn(array &$payload, array $columns, string $column, mixed $value): void
    {
        if (in_array($column, $columns, true)) {
            $payload[$column] = $value;
        }
    }

    private function parseLdif(string $ldif): array
    {
        $entries = [];
        $current = [];

        foreach (preg_split('/\R/', $ldif) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            $current[$key] ??= [];
            $current[$key][] = $value;
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? '127.0.0.1';
        $port = $connection->port ?? $connection->ldap_port ?? 389;
        $scheme = $connection->scheme ?? $connection->protocol ?? 'ldap';

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_dn
            ?? $connection->admin_dn
            ?? $connection->username
            ?? $connection->user_dn
            ?? 'cn=admin,dc=petra,dc=ac,dc=id'
        );
    }

    private function bindPassword(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_password
            ?? $connection->password
            ?? $connection->bind_pass
            ?? ''
        );
    }

    private function baseDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->base_dn
            ?? $connection->root_dn
            ?? $connection->default_base_dn
            ?? 'dc=petra,dc=ac,dc=id'
        );
    }
}
