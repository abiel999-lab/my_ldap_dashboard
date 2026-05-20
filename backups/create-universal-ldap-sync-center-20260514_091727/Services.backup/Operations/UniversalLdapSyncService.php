<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\UniversalLdapEntry;
use App\Models\Operations\LdapSyncBatch;
use App\Models\Operations\OperationJob;
use App\Models\Operations\OperationJobLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class UniversalLdapSyncService
{
    public function sync(LdapSyncBatch $batch, OperationJob $operationJob): array
    {
        $connection = $this->connection($batch);

        if (! $connection) {
            return ['ok' => false, 'message' => 'LDAP connection not found.'];
        }

        if (! $connection->is_active) {
            return ['ok' => false, 'message' => 'LDAP connection is not active.'];
        }

        if (blank($batch->effective_base_dn)) {
            return ['ok' => false, 'message' => 'Effective Sync DN is empty.'];
        }

        $this->log($operationJob, 'info', 'Starting universal LDAP sync.', [
            'ldap_connection_id' => $connection->id,
            'effective_base_dn' => $batch->effective_base_dn,
            'filter' => $batch->filter,
            'search_scope' => $batch->search_scope,
        ]);

        $command = $this->buildCommand($connection, $batch);

        $process = new Process($command, base_path());
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'message' => $process->getErrorOutput() ?: 'ldapsearch failed.',
            ];
        }

        $entries = $this->parseLdif($process->getOutput());
        $now = now();

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach (array_chunk($entries, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $entry) {
                try {
                    $dn = (string) ($entry['dn'] ?? '');

                    if ($dn === '') {
                        $failed++;
                        continue;
                    }

                    $attributes = $entry['attributes'] ?? [];
                    $objectClasses = $this->values($attributes['objectClass'] ?? []);
                    $entryUuid = $this->firstValue($attributes['entryUUID'] ?? $attributes['entryUuid'] ?? []);

                    $normalized = [
                        'ldap_connection_id' => $connection->id,
                        'dn' => $dn,
                        'parent_dn' => $this->parentDn($dn),
                        'rdn' => $this->rdn($dn),
                        'entry_uuid' => $entryUuid,
                        'entry_type' => $this->detectType($objectClasses, $dn),
                        'object_classes' => json_encode($objectClasses, JSON_UNESCAPED_SLASHES),
                        'attributes' => json_encode($attributes, JSON_UNESCAPED_SLASHES),
                        'raw_ldif' => $entry['raw_ldif'] ?? null,
                        'sync_hash' => hash('sha256', json_encode($attributes, JSON_UNESCAPED_SLASHES)),
                        'modify_timestamp' => $this->parseLdapTimestamp($this->firstValue($attributes['modifyTimestamp'] ?? [])),
                        'last_synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $exists = UniversalLdapEntry::query()
                        ->where('ldap_connection_id', $connection->id)
                        ->where('dn', $dn)
                        ->exists();

                    $exists ? $updated++ : $created++;

                    $rows[] = $normalized;
                } catch (Throwable) {
                    $failed++;
                }
            }

            if ($rows !== []) {
                DB::table('universal_ldap_entries')->upsert(
                    $rows,
                    ['ldap_connection_id', 'dn'],
                    [
                        'parent_dn',
                        'rdn',
                        'entry_uuid',
                        'entry_type',
                        'object_classes',
                        'attributes',
                        'raw_ldif',
                        'sync_hash',
                        'modify_timestamp',
                        'last_synced_at',
                        'updated_at',
                    ]
                );
            }
        }

        return [
            'ok' => true,
            'message' => 'Universal LDAP sync completed.',
            'total_entries' => count($entries),
            'created_entries' => $created,
            'updated_entries' => $updated,
            'failed_entries' => $failed,
        ];
    }

    private function buildCommand(LdapConnection $connection, LdapSyncBatch $batch): array
    {
        $command = [
            'ldapsearch',
            '-x',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-H',
            $this->ldapUri($connection),
            '-D',
            (string) $connection->bind_dn,
            '-w',
            (string) $connection->bind_password,
            '-b',
            (string) $batch->effective_base_dn,
            '-s',
            (string) ($batch->search_scope ?: 'sub'),
            '-z',
            (string) ((int) ($batch->size_limit ?: 5000)),
            '-E',
            'pr='.$batch->page_size.'/noprompt',
            (string) $batch->filter,
        ];

        foreach ($batch->attribute_list as $attribute) {
            $command[] = $attribute;
        }

        return $command;
    }

    private function parseLdif(string $ldif): array
    {
        $ldif = str_replace(["\r\n", "\r"], "\n", $ldif);
        $blocks = preg_split("/\n\s*\n/", trim($ldif));
        $entries = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            $normalizedLines = [];

            foreach ($lines as $line) {
                if (str_starts_with($line, ' ') && $normalizedLines !== []) {
                    $normalizedLines[count($normalizedLines) - 1] .= substr($line, 1);
                    continue;
                }

                $normalizedLines[] = $line;
            }

            $dn = null;
            $attributes = [];

            foreach ($normalizedLines as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = ltrim($value);

                if (str_starts_with($value, ':')) {
                    $value = base64_decode(trim(substr($value, 1))) ?: trim($value);
                } else {
                    $value = trim($value);
                }

                if ($key === 'dn') {
                    $dn = $value;
                    continue;
                }

                $attributes[$key] ??= [];
                $attributes[$key][] = $value;
            }

            if ($dn) {
                $entries[] = [
                    'dn' => $dn,
                    'attributes' => $attributes,
                    'raw_ldif' => $block,
                ];
            }
        }

        return $entries;
    }

    private function connection(LdapSyncBatch $batch): ?LdapConnection
    {
        return $batch->ldapConnection ?: LdapConnection::query()->find($batch->ldap_connection_id);
    }

    private function ldapUri(LdapConnection $connection): string
    {
        return ($connection->use_ssl ? 'ldaps' : 'ldap').'://'.$connection->host.':'.$connection->port;
    }

    private function detectType(array $objectClasses, string $dn): string
    {
        $classes = array_map('strtolower', $objectClasses);

        if (in_array('inetorgperson', $classes, true) || in_array('person', $classes, true)) {
            return 'user';
        }

        if (in_array('groupofnames', $classes, true) || in_array('groupofuniquenames', $classes, true) || in_array('posixgroup', $classes, true)) {
            return 'group';
        }

        if (in_array('organizationalunit', $classes, true) || str_starts_with(strtolower($dn), 'ou=')) {
            return 'ou';
        }

        if (str_starts_with(strtolower($dn), 'cn=')) {
            return 'cn';
        }

        return 'unknown';
    }

    private function parentDn(string $dn): ?string
    {
        $parts = explode(',', $dn);
        array_shift($parts);

        return $parts ? implode(',', $parts) : null;
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn)[0] ?? $dn;
    }

    private function values(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($item): string => (string) $item)
            ->filter()
            ->values()
            ->all();
    }

    private function firstValue(mixed $value): ?string
    {
        return $this->values($value)[0] ?? null;
    }

    private function parseLdapTimestamp(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = preg_replace('/Z$/', '', $value);

        if (! preg_match('/^\d{14}$/', $value)) {
            return null;
        }

        return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2).' '.substr($value, 8, 2).':'.substr($value, 10, 2).':'.substr($value, 12, 2);
    }

    private function log(OperationJob $job, string $level, string $message, array $context = []): void
    {
        OperationJobLog::query()->create([
            'operation_job_id' => $job->id,
            'level' => $level,
            'event' => 'universal_ldap_sync',
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
