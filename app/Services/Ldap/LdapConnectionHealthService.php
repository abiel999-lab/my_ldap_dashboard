<?php

namespace App\Services\Ldap;

use App\Models\Directory\LdapConnection;
use App\Services\Observability\UnifiedActivityLogger;
use Throwable;

class LdapConnectionHealthService
{
    public function check(LdapConnection $connection): array
    {
        $startedAt = microtime(true);

        $result = $this->connectAndBind($connection, $startedAt);

        if (! $result['ok']) {
            $this->logOperation($connection, 'test_connection', $result);
            return $result;
        }

        $ldap = $result['ldap'];

        $search = @ldap_read(
            $ldap,
            $connection->base_dn,
            '(objectClass=*)',
            ['dn'],
            0,
            1,
            max(1, (int) $connection->timeout)
        );

        if ($search === false) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP bind succeeded, but base DN read failed: '.$this->getLdapError($ldap),
            );
        }

        @ldap_unbind($ldap);

        $final = [
            'ok' => true,
            'status' => 'healthy',
            'message' => sprintf(
                'LDAP bind and base DN read succeeded. Host: %s, Port: %s, Base DN: %s.',
                $connection->host,
                $connection->port,
                $connection->base_dn,
            ),
            'duration_ms' => $this->durationMs($startedAt),
            'entry_count' => null,
        ];

        $this->logOperation($connection, 'test_connection', $final);

        return $final;
    }

    public function searchTest(
        LdapConnection $connection,
        ?string $baseDn = null,
        string $filter = '(objectClass=*)',
        array $attributes = ['dn'],
        int $limit = 20,
    ): array {
        $startedAt = microtime(true);

        $result = $this->connectAndBind($connection, $startedAt);

        if (! $result['ok']) {
            return $result;
        }

        $ldap = $result['ldap'];
        $effectiveBaseDn = filled($baseDn) ? $baseDn : $connection->base_dn;
        $effectiveLimit = max(1, min($limit, 100));

        $search = @ldap_search(
            $ldap,
            $effectiveBaseDn,
            $filter,
            $attributes,
            0,
            $effectiveLimit,
            max(1, (int) $connection->timeout)
        );

        if ($search === false) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP search failed: '.$this->getLdapError($ldap),
                entryCount: 0,
            );
        }

        $entries = @ldap_get_entries($ldap, $search);

        if (! is_array($entries)) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP search succeeded, but entries could not be parsed.',
                entryCount: 0,
            );
        }

        $count = (int) ($entries['count'] ?? 0);

        @ldap_unbind($ldap);

        return [
            'ok' => true,
            'status' => 'healthy',
            'message' => sprintf(
                'LDAP search succeeded. Base DN: %s, Filter: %s, Returned entries: %s, Limit: %s.',
                $effectiveBaseDn,
                $filter,
                $count,
                $effectiveLimit,
            ),
            'duration_ms' => $this->durationMs($startedAt),
            'entry_count' => $count,
            'base_dn' => $effectiveBaseDn,
            'filter' => $filter,
            'limit' => $effectiveLimit,
        ];
    }


    public function schemaTest(LdapConnection $connection): array
    {
        $startedAt = microtime(true);

        $result = $this->connectAndBind($connection, $startedAt);

        if (! $result['ok']) {
            return $result;
        }

        $ldap = $result['ldap'];

        $rootDseSearch = @ldap_read(
            $ldap,
            '',
            '(objectClass=*)',
            ['subschemaSubentry', 'namingContexts', 'supportedLDAPVersion', 'supportedControl', 'vendorName'],
            0,
            1,
            max(1, (int) $connection->timeout)
        );

        if ($rootDseSearch === false) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'RootDSE read failed: '.$this->getLdapError($ldap),
            );
        }

        $rootDseEntries = @ldap_get_entries($ldap, $rootDseSearch);

        if (! is_array($rootDseEntries)) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'RootDSE read succeeded, but response could not be parsed.',
            );
        }

        $subschemaDn = $rootDseEntries[0]['subschemasubentry'][0] ?? null;
        $namingContextCount = (int) ($rootDseEntries[0]['namingcontexts']['count'] ?? 0);

        $attributeTypesCount = null;
        $objectClassesCount = null;
        $schemaMessage = 'RootDSE read succeeded.';

        if (filled($subschemaDn)) {
            $schemaSearch = @ldap_read(
                $ldap,
                $subschemaDn,
                '(objectClass=*)',
                ['attributeTypes', 'objectClasses'],
                0,
                1,
                max(1, (int) $connection->timeout)
            );

            if ($schemaSearch !== false) {
                $schemaEntries = @ldap_get_entries($ldap, $schemaSearch);

                if (is_array($schemaEntries)) {
                    $attributeTypesCount = (int) ($schemaEntries[0]['attributetypes']['count'] ?? 0);
                    $objectClassesCount = (int) ($schemaEntries[0]['objectclasses']['count'] ?? 0);

                    $schemaMessage = sprintf(
                        'RootDSE and subschema read succeeded. Subschema DN: %s, Attribute Types: %s, Object Classes: %s.',
                        $subschemaDn,
                        $attributeTypesCount,
                        $objectClassesCount,
                    );
                }
            } else {
                $schemaMessage = sprintf(
                    'RootDSE read succeeded, but subschema read failed. Subschema DN: %s. Error: %s',
                    $subschemaDn,
                    $this->getLdapError($ldap),
                );
            }
        } else {
            $schemaMessage = sprintf(
                'RootDSE read succeeded, but subschemaSubentry was not advertised. Naming Contexts: %s.',
                $namingContextCount,
            );
        }

        @ldap_unbind($ldap);

        return [
            'ok' => true,
            'status' => 'healthy',
            'message' => $schemaMessage,
            'duration_ms' => $this->durationMs($startedAt),
            'subschema_dn' => $subschemaDn,
            'naming_context_count' => $namingContextCount,
            'attribute_types_count' => $attributeTypesCount,
            'object_classes_count' => $objectClassesCount,
        ];
    }



    private function logOperation(
        LdapConnection $connection,
        string $action,
        array $result,
        array $extra = []
    ): void {
        try {
            $ok = (bool) ($result['ok'] ?? false);

            $context = array_merge([
                'operation_type' => 'ldap_connection',
                'event' => $action,
                'target_type' => 'ldap_connection',
                'target_id' => $connection->getKey(),
                'target_label' => $connection->name,
                'target_dn' => $connection->base_dn,
                'host' => $connection->host,
                'port' => $connection->port,
                'base_dn' => $connection->base_dn,
                'status' => $result['status'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
                'result' => $result,
                'source' => 'service',
                'total' => 1,
                'success' => $ok ? 1 : 0,
                'failed' => $ok ? 0 : 1,
                'skipped' => 0,
            ], $extra);

            $logger = app(UnifiedActivityLogger::class);

            if ($ok) {
                $logger->success(
                    module: 'directory.ldap_connections',
                    action: $action,
                    message: (string) ($result['message'] ?? 'LDAP connection operation succeeded.'),
                    context: $context,
                );
            } else {
                $logger->failed(
                    module: 'directory.ldap_connections',
                    action: $action,
                    message: (string) ($result['message'] ?? 'LDAP connection operation failed.'),
                    context: $context,
                );
            }
        } catch (Throwable) {
            // Logging must never break LDAP connection checks.
        }
    }


    private function connectAndBind(LdapConnection $connection, float $startedAt): array
    {
        if (! function_exists('ldap_connect')) {
            return $this->failedResult(
                startedAt: $startedAt,
                message: 'PHP LDAP extension is not installed or not enabled.',
            );
        }

        $url = $this->buildConnectionUrl($connection);

        try {
            $lastWarning = null;

            set_error_handler(function (int $severity, string $message) use (&$lastWarning): bool {
                $lastWarning = $message;

                return true;
            });

            $ldap = ldap_connect($url);

            restore_error_handler();

            if ($ldap === false) {
                return $this->failedResult(
                    startedAt: $startedAt,
                    message: $lastWarning ?: 'Unable to initialize LDAP connection.',
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
                    );
                }
            }

            $bindDn = $connection->bind_dn;
            $bindPassword = $connection->bind_password;

            if (filled($bindDn)) {
                $bound = @ldap_bind($ldap, $bindDn, (string) $bindPassword);
            } else {
                $bound = @ldap_bind($ldap);
            }

            if ($bound !== true) {
                return $this->failedResult(
                    startedAt: $startedAt,
                    message: 'LDAP bind failed: '.$this->getLdapError($ldap),
                );
            }

            return [
                'ok' => true,
                'status' => 'healthy',
                'message' => 'LDAP bind succeeded.',
                'duration_ms' => $this->durationMs($startedAt),
                'ldap' => $ldap,
            ];
        } catch (Throwable $exception) {
            restore_error_handler();

            return $this->failedResult(
                startedAt: $startedAt,
                message: 'LDAP health check exception: '.$exception->getMessage(),
            );
        }
    }

    private function buildConnectionUrl(LdapConnection $connection): string
    {
        $scheme = $connection->use_ssl ? 'ldaps' : 'ldap';

        return sprintf('%s://%s:%s', $scheme, $connection->host, $connection->port);
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

    private function failedResult(float $startedAt, string $message, ?int $entryCount = null): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $message,
            'duration_ms' => $this->durationMs($startedAt),
            'entry_count' => $entryCount,
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
