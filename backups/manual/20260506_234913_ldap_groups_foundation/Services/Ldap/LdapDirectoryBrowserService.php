<?php

namespace App\Services\Ldap;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Support\Security\RedactsSensitiveData;
use Throwable;

class LdapDirectoryBrowserService
{
    public function refreshCache(
        LdapConnection $connection,
        ?string $baseDn = null,
        string $filter = '(objectClass=*)',
        int $limit = 200,
    ): array {
        $startedAt = microtime(true);

        $baseDn = filled($baseDn) ? $baseDn : $connection->base_dn;
        $limit = max(1, min($limit, 1000));

        $bind = $this->connectAndBind($connection, $startedAt);

        if (! $bind['ok']) {
            return $bind;
        }

        $ldap = $bind['ldap'];

        $attributes = [
            '*',
            '+',
        ];

        $search = @ldap_search(
            $ldap,
            $baseDn,
            $filter,
            $attributes,
            0,
            $limit,
            max(1, (int) $connection->timeout)
        );

        if ($search === false) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP directory search failed: '.$this->getLdapError($ldap),
                count: 0,
            );
        }

        $entries = @ldap_get_entries($ldap, $search);

        if (! is_array($entries)) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP search succeeded but entries could not be parsed.',
                count: 0,
            );
        }

        $count = (int) ($entries['count'] ?? 0);
        $saved = 0;

        for ($i = 0; $i < $count; $i++) {
            $rawEntry = $entries[$i] ?? null;

            if (! is_array($rawEntry)) {
                continue;
            }

            $dn = $rawEntry['dn'] ?? null;

            if (! is_string($dn) || $dn === '') {
                continue;
            }

            $normalized = $this->normalizeEntry($rawEntry);
            $objectClasses = $normalized['objectclass'] ?? [];
            $entryType = $this->guessEntryType($objectClasses, $dn);

            LdapDirectoryEntry::query()->updateOrCreate(
                [
                    'ldap_connection_id' => $connection->id,
                    'entry_dn' => $dn,
                ],
                [
                    'connection_name' => $connection->name,
                    'base_dn' => $baseDn,
                    'parent_dn' => $this->parentDn($dn),
                    'entry_rdn' => $this->rdn($dn),
                    'entry_type' => $entryType,
                    'object_classes' => $objectClasses,
                    'attributes' => RedactsSensitiveData::redact($normalized),
                    'operational_attributes' => null,
                    'depth' => $this->depth($dn),
                    'source_hash' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES)),
                    'last_seen_at' => now(),
                ],
            );

            $saved++;
        }

        @ldap_unbind($ldap);

        return [
            'ok' => true,
            'status' => 'success',
            'message' => sprintf(
                'LDAP directory cache refreshed. Base DN: %s, Filter: %s, Saved entries: %s, Limit: %s.',
                $baseDn,
                $filter,
                $saved,
                $limit,
            ),
            'duration_ms' => $this->durationMs($startedAt),
            'count' => $saved,
            'base_dn' => $baseDn,
            'filter' => $filter,
            'limit' => $limit,
        ];
    }

    private function connectAndBind(LdapConnection $connection, float $startedAt): array
    {
        if (! function_exists('ldap_connect')) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'PHP LDAP extension is not installed or not enabled.',
                count: 0,
            );
        }

        try {
            $scheme = $connection->use_ssl ? 'ldaps' : 'ldap';
            $url = sprintf('%s://%s:%s', $scheme, $connection->host, $connection->port);

            $ldap = @ldap_connect($url);

            if ($ldap === false) {
                return $this->failedResult(
                    startedAt: $startedAt,
                    message: 'Unable to initialize LDAP connection.',
                    count: 0,
                );
            }

            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, max(1, (int) $connection->timeout));

            if ($connection->use_tls) {
                $tlsStarted = @ldap_start_tls($ldap);

                if ($tlsStarted !== true) {
                    return $this->failedResult(
                        startedAt: $startedAt,
                        message: 'Unable to start TLS: '.$this->getLdapError($ldap),
                        count: 0,
                    );
                }
            }

            if (filled($connection->bind_dn)) {
                $bound = @ldap_bind($ldap, $connection->bind_dn, (string) $connection->bind_password);
            } else {
                $bound = @ldap_bind($ldap);
            }

            if ($bound !== true) {
                return $this->failedResult(
                    startedAt: $startedAt,
                    message: 'LDAP bind failed: '.$this->getLdapError($ldap),
                    count: 0,
                );
            }

            return [
                'ok' => true,
                'ldap' => $ldap,
            ];
        } catch (Throwable $exception) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP browser exception: '.$exception->getMessage(),
                count: 0,
            );
        }
    }

    private function normalizeEntry(array $entry): array
    {
        $normalized = [];

        foreach ($entry as $key => $value) {
            if (is_int($key) || $key === 'count' || $key === 'dn') {
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $values = [];

            $valueCount = (int) ($value['count'] ?? 0);

            for ($i = 0; $i < $valueCount; $i++) {
                if (array_key_exists($i, $value)) {
                    $values[] = $value[$i];
                }
            }

            $normalized[strtolower((string) $key)] = $values;
        }

        return $normalized;
    }

    private function guessEntryType(array $objectClasses, string $dn): string
    {
        $classes = array_map(fn ($class): string => strtolower((string) $class), $objectClasses);

        if (in_array('organizationalunit', $classes, true)) {
            return 'organizational_unit';
        }

        if (in_array('groupofnames', $classes, true) || in_array('groupofuniquenames', $classes, true) || in_array('posixgroup', $classes, true)) {
            return 'group';
        }

        if (in_array('person', $classes, true) || in_array('inetorgperson', $classes, true) || in_array('organizationalperson', $classes, true)) {
            return 'user';
        }

        if (str_starts_with(strtolower($dn), 'dc=')) {
            return 'domain';
        }

        return 'entry';
    }

    private function rdn(string $dn): string
    {
        return explode(',', $dn, 2)[0] ?? $dn;
    }

    private function parentDn(string $dn): ?string
    {
        $parts = explode(',', $dn, 2);

        return $parts[1] ?? null;
    }

    private function depth(string $dn): int
    {
        return substr_count($dn, ',');
    }

    private function getLdapError(mixed $ldap): string
    {
        if (! is_object($ldap) && ! is_resource($ldap)) {
            return 'Unknown LDAP error.';
        }

        $error = @ldap_error($ldap);
        $errno = @ldap_errno($ldap);

        return trim(sprintf('[%s] %s', $errno ?: 'unknown', $error ?: 'Unknown LDAP error'));
    }

    private function failedResult(float $startedAt, string $message, int $count): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $message,
            'duration_ms' => $this->durationMs($startedAt),
            'count' => $count,
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
