<?php

namespace App\Console\Commands\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Services\Radius\WifiReadinessSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class VerifyLdapSyncStateCommand extends Command
{
    protected $signature = 'iam:verify-ldap-sync-state
        {--connection= : LDAP connection ID}
        {--ldap-uri= : Override LDAP URI, example ldap://203.189.120.51:30389}
        {--bind-dn= : Override bind DN}
        {--bind-password= : Override bind password}
        {--base-dn= : Override base DN}
        {--json : Output JSON only}';

    protected $description = 'Verify PostgreSQL LDAP mirror against real LDAP source of truth for users, directory objects, schema, and WiFi readiness.';

    public function handle(): int
    {
        try {
            $connection = $this->resolveConnection();

            if (! $connection) {
                $this->error('No active LDAP connection found.');

                return self::FAILURE;
            }

            $ldapUri = $this->option('ldap-uri') ?: $this->buildLdapUri($connection);
            $baseDn = $this->option('base-dn') ?: (string) ($connection->base_dn ?? 'dc=petra,dc=ac,dc=id');
            $bindDn = $this->option('bind-dn') ?: $this->guessBindDn($connection, $baseDn);
            $bindPassword = $this->option('bind-password') ?: env('LDAP_VERIFY_BIND_PASSWORD') ?: env('LDAP_ADMIN_PASSWORD') ?: null;

            if (! $bindDn || ! $bindPassword) {
                $this->warn('Bind DN/password was not fully provided.');
                $this->warn('Use --bind-dn and --bind-password, or set LDAP_VERIFY_BIND_PASSWORD in .env.');
            }

            $summary = [
                'connection' => [
                    'id' => $connection->id ?? null,
                    'name' => $connection->name ?? null,
                    'ldap_uri' => $ldapUri,
                    'base_dn' => $baseDn,
                ],
                'users' => $this->verifyUsers($connection, $ldapUri, $baseDn, $bindDn, $bindPassword),
                'directory_objects' => $this->verifyDirectoryObjects($connection, $ldapUri, $baseDn, $bindDn, $bindPassword),
                'schema' => $this->verifySchema($connection),
                'wifi_readiness' => $this->verifyWifiReadiness(),
                'generated_at' => now()->toDateTimeString(),
            ];

            $summary['final_status'] = $this->finalStatus($summary);

            if ($this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $summary['final_status'] === 'failed' ? self::FAILURE : self::SUCCESS;
            }

            $this->printHuman($summary);

            return $summary['final_status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            if ($this->option('json')) {
                $this->line(json_encode([
                    'final_status' => 'failed',
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }
    }

    private function resolveConnection(): ?LdapConnection
    {
        $connectionId = $this->option('connection');

        return LdapConnection::query()
            ->when($connectionId, fn ($query) => $query->whereKey((int) $connectionId))
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    private function buildLdapUri(LdapConnection $connection): string
    {
        $host = (string) ($connection->host ?? '127.0.0.1');
        $port = (int) ($connection->port ?? 389);
        $scheme = ((bool) ($connection->use_ssl ?? false)) ? 'ldaps' : 'ldap';

        return "{$scheme}://{$host}:{$port}";
    }

    private function guessBindDn(LdapConnection $connection, string $baseDn): ?string
    {
        foreach (['bind_dn', 'username', 'admin_dn'] as $field) {
            if (isset($connection->{$field}) && $connection->{$field}) {
                return (string) $connection->{$field};
            }
        }

        return 'cn=admin,' . $baseDn;
    }

    private function verifyUsers(LdapConnection $connection, string $ldapUri, string $baseDn, ?string $bindDn, ?string $bindPassword): array
    {
        $peopleBaseDn = 'ou=people,' . $baseDn;

        $ldap = $this->ldapCounts($ldapUri, $bindDn, $bindPassword, $peopleBaseDn, [
            'uid_total' => '(uid=*)',
            'sambaSamAccount' => '(&(uid=*)(objectClass=sambaSamAccount))',
            'sambaSID' => '(&(uid=*)(sambaSID=*))',
            'sambaAcctFlags' => '(&(uid=*)(sambaAcctFlags=*))',
            'sambaNTPassword' => '(&(uid=*)(sambaNTPassword=*))',
            'userPassword' => '(&(uid=*)(userPassword=*))',
            'petraVlan' => '(&(uid=*)(petraVlan=*))',
        ]);

        $dbQuery = LdapUserEntry::query()
            ->where('ldap_connection_id', $connection->id)
            ->where('dn', 'like', '%ou=people,' . $baseDn)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', [
                        'missing_from_ldap',
                        'deleted_from_ldap',
                        'missing',
                        'deleted',
                    ]);
            });

        $db = [
            'uid_total' => (clone $dbQuery)->count(),
            'sambaSamAccount' => (clone $dbQuery)->where('attributes', 'like', '%sambaSamAccount%')->count(),
            'sambaSID' => (clone $dbQuery)->where('attributes', 'like', '%sambaSID%')->count(),
            'sambaAcctFlags' => (clone $dbQuery)->where('attributes', 'like', '%sambaAcctFlags%')->count(),
            'sambaNTPassword' => (clone $dbQuery)->where('attributes', 'like', '%sambaNTPassword%')->count(),
            'userPassword' => (clone $dbQuery)->where('attributes', 'like', '%userPassword%')->count(),
            'petraVlan' => (clone $dbQuery)->where('attributes', 'like', '%petraVlan%')->count(),
        ];

        return [
            'ldap' => $ldap,
            'db' => $db,
            'verified' => $this->sameKeys($ldap, $db, ['uid_total', 'sambaSamAccount', 'sambaSID', 'sambaAcctFlags', 'sambaNTPassword', 'userPassword', 'petraVlan']),
            'status' => $this->sameKeys($ldap, $db, ['uid_total', 'sambaSamAccount', 'sambaSID', 'sambaAcctFlags'])
                ? 'verified_core'
                : 'mismatch',
        ];
    }

    private function verifyDirectoryObjects(LdapConnection $connection, string $ldapUri, string $baseDn, ?string $bindDn, ?string $bindPassword): array
    {
        $ldapTotal = $this->ldapCount($ldapUri, $bindDn, $bindPassword, $baseDn, '(objectClass=*)');

        $dbTotal = Schema::hasTable('ldap_directory_entries')
            ? DB::table('ldap_directory_entries')
                ->where('ldap_connection_id', $connection->id)
                ->where(function ($query): void {
                    $query->whereNull('status')
                        ->orWhereNotIn('status', [
                            'missing_from_ldap',
                            'deleted_from_ldap',
                            'missing',
                            'deleted',
                        ]);
                })
                ->count()
            : null;

        return [
            'ldap_total' => $ldapTotal,
            'db_total' => $dbTotal,
            'verified' => $ldapTotal !== null && $dbTotal !== null && (int) $ldapTotal === (int) $dbTotal,
            'status' => $ldapTotal !== null && $dbTotal !== null && (int) $ldapTotal === (int) $dbTotal ? 'verified' : 'mismatch_or_unavailable',
        ];
    }

    private function verifySchema(LdapConnection $connection): array
    {
        if (! Schema::hasTable('ldap_schema_entries')) {
            return [
                'db_total' => null,
                'verified' => false,
                'status' => 'table_missing',
            ];
        }

        $query = DB::table('ldap_schema_entries');

        if (Schema::hasColumn('ldap_schema_entries', 'ldap_connection_id')) {
            $query->where('ldap_connection_id', $connection->id);
        }

        $total = $query->count();

        return [
            'db_total' => $total,
            'verified' => $total > 0,
            'status' => $total > 0 ? 'available' : 'empty',
        ];
    }

    private function verifyWifiReadiness(): array
    {
        if (! class_exists(WifiReadinessSyncService::class)) {
            return [
                'verified' => false,
                'status' => 'service_missing',
            ];
        }

        $summary = app(WifiReadinessSyncService::class)->verifyCurrentMirror();

        return [
            'verified' => (bool) ($summary['verified'] ?? false),
            'decision' => $summary['decision'] ?? 'UNKNOWN',
            'stats' => $summary['stats'] ?? [],
            'status' => ($summary['decision'] ?? 'UNKNOWN') === 'READY'
                ? 'ready'
                : 'verified_with_warnings',
        ];
    }

    private function ldapCounts(string $ldapUri, ?string $bindDn, ?string $bindPassword, string $baseDn, array $filters): array
    {
        $results = [];

        foreach ($filters as $key => $filter) {
            $results[$key] = $this->ldapCount($ldapUri, $bindDn, $bindPassword, $baseDn, $filter);
        }

        return $results;
    }

    private function ldapCount(string $ldapUri, ?string $bindDn, ?string $bindPassword, string $baseDn, string $filter): ?int
    {
        if (! $bindDn || ! $bindPassword) {
            return null;
        }

        $command = [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $ldapUri,
            '-D',
            $bindDn,
            '-w',
            $bindPassword,
            '-b',
            $baseDn,
            $filter,
            'dn',
        ];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        preg_match_all('/^dn:/m', $process->getOutput(), $matches);

        return count($matches[0]);
    }

    private function sameKeys(array $left, array $right, array $keys): bool
    {
        foreach ($keys as $key) {
            if ((string) ($left[$key] ?? '') !== (string) ($right[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function finalStatus(array $summary): string
    {
        $hardFailures = [
            ! ($summary['users']['verified'] ?? false),
            ! ($summary['directory_objects']['verified'] ?? false),
            ! ($summary['schema']['verified'] ?? false),
            ! ($summary['wifi_readiness']['verified'] ?? false),
        ];

        if (in_array(true, $hardFailures, true)) {
            return 'success_with_warnings';
        }

        return ($summary['wifi_readiness']['decision'] ?? 'UNKNOWN') === 'READY'
            ? 'success'
            : 'success_with_warnings';
    }

    private function printHuman(array $summary): void
    {
        $this->info('LDAP SYNC STATE VERIFICATION');
        $this->line('Connection: ' . ($summary['connection']['name'] ?? '-') . ' [ID ' . ($summary['connection']['id'] ?? '-') . ']');
        $this->line('LDAP URI  : ' . ($summary['connection']['ldap_uri'] ?? '-'));
        $this->line('Base DN   : ' . ($summary['connection']['base_dn'] ?? '-'));
        $this->newLine();

        $this->line('1. USERS');
        $this->line('   LDAP: ' . json_encode($summary['users']['ldap'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->line('   DB  : ' . json_encode($summary['users']['db'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->line('   verified=' . (($summary['users']['verified'] ?? false) ? 'true' : 'false'));
        $this->newLine();

        $this->line('2. DIRECTORY OBJECTS');
        $this->line('   LDAP total=' . (($summary['directory_objects']['ldap_total'] ?? null) ?? 'N/A'));
        $this->line('   DB total=' . (($summary['directory_objects']['db_total'] ?? null) ?? 'N/A'));
        $this->line('   verified=' . (($summary['directory_objects']['verified'] ?? false) ? 'true' : 'false'));
        $this->newLine();

        $this->line('3. SCHEMA');
        $this->line('   DB total=' . (($summary['schema']['db_total'] ?? null) ?? 'N/A'));
        $this->line('   verified=' . (($summary['schema']['verified'] ?? false) ? 'true' : 'false'));
        $this->newLine();

        $this->line('4. WIFI READINESS');
        $this->line('   decision=' . ($summary['wifi_readiness']['decision'] ?? 'UNKNOWN'));
        $this->line('   verified=' . (($summary['wifi_readiness']['verified'] ?? false) ? 'true' : 'false'));
        $this->line('   stats=' . json_encode($summary['wifi_readiness']['stats'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->newLine();

        $this->info('FINAL_STATUS=' . ($summary['final_status'] ?? 'unknown'));
    }
}
