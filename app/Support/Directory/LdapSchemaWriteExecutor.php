<?php

namespace App\Support\Directory;

use Symfony\Component\Process\Process;

class LdapSchemaWriteExecutor
{
    public function execute($connection, string $operation, string $schemaType, ?string $schemaConfigDn, string $definition, ?string $oldDefinition = null): array
    {
        $method = $this->value($connection, ['schema_write_method'], 'disabled');
        $enabled = (bool) ($connection->schema_write_enabled ?? false);

        if (! $enabled || $method === 'disabled') {
            throw new \RuntimeException('Schema write is disabled for LDAP connection: '.($connection->name ?? $connection->id));
        }

        return match ($method) {
            'simple_bind' => $this->executeSimpleBind($connection, $operation, $schemaType, $schemaConfigDn, $definition, $oldDefinition),
            'kubernetes_ldapi_external' => $this->executeKubernetesLdapiExternal($connection, $operation, $schemaType, $schemaConfigDn, $definition, $oldDefinition),
            default => throw new \RuntimeException('Unsupported schema write method: '.$method),
        };
    }

    private function executeSimpleBind($connection, string $operation, string $schemaType, ?string $schemaConfigDn, string $definition, ?string $oldDefinition = null): array
    {
        $attribute = LdapSchemaDefinitionParser::schemaTypeToConfigAttribute($schemaType);

        $schemaConfigDn = trim((string) $schemaConfigDn);

        if ($schemaConfigDn === '') {
            throw new \RuntimeException('schema_config_dn is required for simple_bind schema write.');
        }

        $exactOldDefinition = null;

        if (in_array($operation, ['replace', 'delete'], true)) {
            $exactOldDefinition = $this->findExactConfigValueSimpleBind(
                $connection,
                $schemaConfigDn,
                $attribute,
                $schemaType,
                $oldDefinition ?: $definition
            );
        }

        $ldif = $this->buildModifyLdif($operation, $schemaConfigDn, $attribute, $definition, $exactOldDefinition ?: $oldDefinition);

        return $this->runLdapModifySimpleBind($connection, $ldif, [
            'operation' => $operation,
            'schema_type' => $schemaType,
            'schema_config_dn' => $schemaConfigDn,
            'schema_attribute' => $attribute,
            'definition' => $definition,
            'old_definition' => $oldDefinition,
            'exact_old_definition' => $exactOldDefinition,
            'ldif' => $ldif,
        ]);
    }

    private function executeKubernetesLdapiExternal($connection, string $operation, string $schemaType, ?string $schemaConfigDn, string $definition, ?string $oldDefinition = null): array
    {
        $attribute = LdapSchemaDefinitionParser::schemaTypeToConfigAttribute($schemaType);

        $baseDn = $this->value($connection, ['schema_config_base_dn'], 'cn=schema,cn=config');
        $containerName = $this->value($connection, ['schema_container_name'], 'custom');

        $targetDn = trim((string) $schemaConfigDn);

        if ($targetDn === '' || ! $this->k8sDnExists($connection, $targetDn)) {
            $targetDn = $this->resolveSchemaContainerDn($connection, $baseDn, $containerName);
        }

        $exactOldDefinition = null;

        if (in_array($operation, ['replace', 'delete'], true)) {
            $resolved = $this->findExactConfigValueK8s(
                $connection,
                $baseDn,
                $attribute,
                $schemaType,
                $oldDefinition ?: $definition
            );

            if ($resolved) {
                $targetDn = $resolved['dn'];
                $exactOldDefinition = $resolved['value'];
            }
        }

        if ($operation === 'add' && $targetDn === '') {
            $targetDn = $this->createSchemaContainerK8s($connection, $baseDn, $containerName, $attribute, $definition);

            return [
                'ok' => true,
                'operation' => 'add',
                'created_container' => true,
                'schema_config_dn' => $targetDn,
                'schema_attribute' => $attribute,
                'definition' => $definition,
                'stdout' => 'Created schema container and added first schema definition.',
                'stderr' => '',
                'exit_code' => 0,
                'ldif' => '(container created with ldapadd)',
            ];
        }

        if ($targetDn === '') {
            throw new \RuntimeException('Unable to resolve schema container DN. Configure schema_container_name for this LDAP connection.');
        }

        $ldif = $this->buildModifyLdif($operation, $targetDn, $attribute, $definition, $exactOldDefinition ?: $oldDefinition);

        return $this->runLdapModifyK8sExternal($connection, $ldif, [
            'operation' => $operation,
            'schema_type' => $schemaType,
            'schema_config_dn' => $targetDn,
            'schema_attribute' => $attribute,
            'definition' => $definition,
            'old_definition' => $oldDefinition,
            'exact_old_definition' => $exactOldDefinition,
            'ldif' => $ldif,
        ]);
    }

    private function buildModifyLdif(string $operation, string $dn, string $attribute, string $definition, ?string $oldDefinition = null): string
    {
        $definition = trim($definition);
        $oldDefinition = trim((string) $oldDefinition);

        if ($operation === 'add') {
            return implode("\n", [
                'dn: '.$dn,
                'changetype: modify',
                'add: '.$attribute,
                $attribute.': '.$definition,
                '',
            ]);
        }

        if ($operation === 'replace') {
            if ($oldDefinition === '') {
                return implode("\n", [
                    'dn: '.$dn,
                    'changetype: modify',
                    'add: '.$attribute,
                    $attribute.': '.$definition,
                    '',
                ]);
            }

            return implode("\n", [
                'dn: '.$dn,
                'changetype: modify',
                'delete: '.$attribute,
                $attribute.': '.$oldDefinition,
                '-',
                'add: '.$attribute,
                $attribute.': '.$definition,
                '',
            ]);
        }

        if ($operation === 'delete') {
            if ($oldDefinition === '') {
                $oldDefinition = $definition;
            }

            return implode("\n", [
                'dn: '.$dn,
                'changetype: modify',
                'delete: '.$attribute,
                $attribute.': '.$oldDefinition,
                '',
            ]);
        }

        throw new \InvalidArgumentException('Unsupported schema operation: '.$operation);
    }

    private function createSchemaContainerK8s($connection, string $baseDn, string $containerName, string $attribute, string $definition): string
    {
        $containerName = $this->normalizeContainerName($containerName);

        $dn = 'cn='.$containerName.','.$baseDn;

        $ldif = implode("\n", [
            'dn: '.$dn,
            'objectClass: olcSchemaConfig',
            'cn: '.$containerName,
            $attribute.': '.$definition,
            '',
        ]);

        $result = $this->runLdapAddK8sExternal($connection, $ldif, [
            'operation' => 'create_schema_container',
            'schema_config_dn' => $dn,
            'schema_attribute' => $attribute,
            'definition' => $definition,
            'ldif' => $ldif,
        ]);

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException($result['stderr'] ?? 'Failed to create schema container.');
        }

        $resolved = $this->resolveSchemaContainerDn($connection, $baseDn, $containerName);

        return $resolved ?: $dn;
    }

    private function resolveSchemaContainerDn($connection, string $baseDn, string $containerName): string
    {
        $containerName = $this->normalizeContainerName($containerName);
        $plainName = $this->stripSchemaIndex($containerName);

        $script = "ldapsearch -LLL -o ldif-wrap=no -Y EXTERNAL -H ldapi:/// -b ".escapeshellarg($baseDn)." -s one '(objectClass=olcSchemaConfig)' dn cn";

        $result = $this->runK8sShell($connection, $script);

        if (($result['exit_code'] ?? 1) !== 0) {
            return '';
        }

        $entries = $this->parseDnCnEntries($result['stdout'] ?? '');

        $exact = null;
        $candidates = [];

        foreach ($entries as $entry) {
            $cn = $entry['cn'] ?? '';
            $dn = $entry['dn'] ?? '';

            if ($cn === $containerName || $dn === 'cn='.$containerName.','.$baseDn) {
                $exact = $dn;
                break;
            }

            if ($this->stripSchemaIndex($cn) === $plainName) {
                $candidates[] = [
                    'dn' => $dn,
                    'cn' => $cn,
                    'index' => $this->schemaIndex($cn),
                ];
            }
        }

        if ($exact) {
            return $exact;
        }

        if ($candidates === []) {
            return '';
        }

        usort($candidates, fn ($a, $b) => ($b['index'] ?? -1) <=> ($a['index'] ?? -1));

        return $candidates[0]['dn'];
    }

    private function findExactConfigValueK8s($connection, string $baseDn, string $attribute, string $schemaType, string $targetDefinition): ?array
    {
        $targetDefinition = LdapSchemaDefinitionParser::cleanDefinition($targetDefinition);
        $targetMeta = LdapSchemaDefinitionParser::parse($schemaType, $targetDefinition);

        $targetOid = $targetMeta['oid'] ?? null;
        $targetPrimaryName = $targetMeta['primary_name'] ?? null;

        $script = "ldapsearch -LLL -o ldif-wrap=no -Y EXTERNAL -H ldapi:/// -b ".escapeshellarg($baseDn)." -s one '(objectClass=olcSchemaConfig)' dn cn ".escapeshellarg($attribute);

        $result = $this->runK8sShell($connection, $script);

        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $entries = $this->parseSchemaValueEntries($result['stdout'] ?? '', $attribute);

        foreach ($entries as $entry) {
            foreach (($entry['values'] ?? []) as $value) {
                $clean = LdapSchemaDefinitionParser::cleanDefinition($value);
                $meta = LdapSchemaDefinitionParser::parse($schemaType, $clean);

                $oid = $meta['oid'] ?? null;
                $primaryName = $meta['primary_name'] ?? null;

                if ($targetOid && $oid && $targetOid === $oid) {
                    return [
                        'dn' => $entry['dn'],
                        'value' => $value,
                    ];
                }

                if ($targetPrimaryName && $primaryName && $targetPrimaryName === $primaryName) {
                    return [
                        'dn' => $entry['dn'],
                        'value' => $value,
                    ];
                }

                if ($clean === $targetDefinition) {
                    return [
                        'dn' => $entry['dn'],
                        'value' => $value,
                    ];
                }
            }
        }

        return null;
    }

    private function findExactConfigValueSimpleBind($connection, string $dn, string $attribute, string $schemaType, string $targetDefinition): ?string
    {
        $targetDefinition = LdapSchemaDefinitionParser::cleanDefinition($targetDefinition);
        $targetMeta = LdapSchemaDefinitionParser::parse($schemaType, $targetDefinition);

        $targetOid = $targetMeta['oid'] ?? null;
        $targetPrimaryName = $targetMeta['primary_name'] ?? null;

        $command = [
            'ldapsearch',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-x',
            '-H',
            $this->ldapUri($connection),
        ];

        $bindDn = $this->value($connection, ['schema_bind_dn', 'config_bind_dn', 'bind_dn', 'username']);
        $password = $this->value($connection, ['schema_bind_password', 'config_bind_password', 'bind_password', 'password']);

        if ($bindDn !== '') {
            $command[] = '-D';
            $command[] = $bindDn;
        }

        if ($password !== '') {
            $command[] = '-w';
            $command[] = $password;
        }

        $command = array_merge($command, [
            '-b',
            $dn,
            '-s',
            'base',
            '(objectClass=*)',
            $attribute,
        ]);

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        foreach ($this->parseConfigValues($process->getOutput(), $attribute) as $value) {
            $clean = LdapSchemaDefinitionParser::cleanDefinition($value);
            $meta = LdapSchemaDefinitionParser::parse($schemaType, $clean);

            $oid = $meta['oid'] ?? null;
            $primaryName = $meta['primary_name'] ?? null;

            if ($targetOid && $oid && $targetOid === $targetOid) {
                return $value;
            }

            if ($targetPrimaryName && $primaryName && $targetPrimaryName === $primaryName) {
                return $value;
            }

            if ($clean === $targetDefinition) {
                return $value;
            }
        }

        return null;
    }

    private function runLdapModifySimpleBind($connection, string $ldif, array $context): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'schema_modify_');
        file_put_contents($tmp, $ldif);

        $command = [
            'ldapmodify',
            '-x',
            '-H',
            $this->ldapUri($connection),
        ];

        $bindDn = $this->value($connection, ['schema_bind_dn', 'config_bind_dn', 'bind_dn', 'username']);
        $password = $this->value($connection, ['schema_bind_password', 'config_bind_password', 'bind_password', 'password']);

        if ($bindDn !== '') {
            $command[] = '-D';
            $command[] = $bindDn;
        }

        if ($password !== '') {
            $command[] = '-w';
            $command[] = $password;
        }

        $command[] = '-f';
        $command[] = $tmp;

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        @unlink($tmp);

        return array_merge($context, [
            'ok' => $process->isSuccessful(),
            'command' => $this->redactedCommand($command),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? 0,
        ]);
    }

    private function runLdapModifyK8sExternal($connection, string $ldif, array $context): array
    {
        $encoded = base64_encode($ldif);
        $remoteFile = '/tmp/schema_modify_'.uniqid().'.ldif';

        $script = implode("\n", [
            "cat <<'EOF' | base64 -d > ".escapeshellarg($remoteFile),
            $encoded,
            'EOF',
            'ldapmodify -Y EXTERNAL -H ldapi:/// -f '.escapeshellarg($remoteFile),
            'STATUS=$?',
            'rm -f '.escapeshellarg($remoteFile),
            'exit $STATUS',
        ]);

        $result = $this->runK8sShell($connection, $script);

        return array_merge($context, [
            'ok' => ($result['exit_code'] ?? 1) === 0,
            'command' => $result['command'] ?? null,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'exit_code' => $result['exit_code'] ?? 1,
        ]);
    }

    private function runLdapAddK8sExternal($connection, string $ldif, array $context): array
    {
        $encoded = base64_encode($ldif);
        $remoteFile = '/tmp/schema_add_'.uniqid().'.ldif';

        $script = implode("\n", [
            "cat <<'EOF' | base64 -d > ".escapeshellarg($remoteFile),
            $encoded,
            'EOF',
            'ldapadd -Y EXTERNAL -H ldapi:/// -f '.escapeshellarg($remoteFile),
            'STATUS=$?',
            'rm -f '.escapeshellarg($remoteFile),
            'exit $STATUS',
        ]);

        $result = $this->runK8sShell($connection, $script);

        return array_merge($context, [
            'ok' => ($result['exit_code'] ?? 1) === 0,
            'command' => $result['command'] ?? null,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'exit_code' => $result['exit_code'] ?? 1,
        ]);
    }

    private function k8sDnExists($connection, string $dn): bool
    {
        if ($dn === '') {
            return false;
        }

        $script = "ldapsearch -LLL -o ldif-wrap=no -Y EXTERNAL -H ldapi:/// -b ".escapeshellarg($dn)." -s base '(objectClass=*)' dn";

        $result = $this->runK8sShell($connection, $script);

        return ($result['exit_code'] ?? 1) === 0 && str_contains($result['stdout'] ?? '', 'dn:');
    }

    private function runK8sShell($connection, string $script): array
    {
        $kubectl = $this->value($connection, ['schema_k8s_kubectl'], 'microk8s kubectl');
        $namespace = $this->value($connection, ['schema_k8s_namespace'], 'default');
        $pod = $this->value($connection, ['schema_k8s_pod']);
        $container = $this->value($connection, ['schema_k8s_container']);

        if ($pod === '') {
            $selector = $this->value($connection, ['schema_k8s_pod_selector']);

            if ($selector !== '') {
                $pod = $this->resolvePodBySelector($kubectl, $namespace, $selector);
            }
        }

        if ($pod === '') {
            throw new \RuntimeException('schema_k8s_pod or schema_k8s_pod_selector is required for kubernetes_ldapi_external.');
        }

        $command = array_merge(
            preg_split('/\s+/', trim($kubectl)) ?: ['kubectl'],
            ['-n', $namespace, 'exec', $pod]
        );

        if ($container !== '') {
            $command[] = '-c';
            $command[] = $container;
        }

        $command = array_merge($command, ['--', 'sh', '-lc', $script]);

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        return [
            'command' => $this->redactedCommand($command),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? 0,
        ];
    }

    private function resolvePodBySelector(string $kubectl, string $namespace, string $selector): string
    {
        $command = array_merge(
            preg_split('/\s+/', trim($kubectl)) ?: ['kubectl'],
            ['-n', $namespace, 'get', 'pods', '-l', $selector, '-o', 'jsonpath={.items[0].metadata.name}']
        );

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }

    private function parseConfigValues(string $output, string $attribute): array
    {
        $output = $this->unfoldLdif($output);
        $values = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim((string) $line);

            if (str_starts_with($line, $attribute.':')) {
                $values[] = trim(substr($line, strlen($attribute) + 1));
            }
        }

        return $values;
    }

    private function parseDnCnEntries(string $output): array
    {
        $entries = [];
        $current = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }

            if (str_starts_with($line, 'dn:')) {
                if ($current !== []) {
                    $entries[] = $current;
                }

                $current = ['dn' => trim(substr($line, 3))];
            }

            if (str_starts_with($line, 'cn:')) {
                $current['cn'] = trim(substr($line, 3));
            }
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function parseSchemaValueEntries(string $output, string $attribute): array
    {
        $output = $this->unfoldLdif($output);

        $entries = [];
        $current = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim((string) $line);

            if ($line === '') {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }

            if (str_starts_with($line, 'dn:')) {
                if ($current !== []) {
                    $entries[] = $current;
                }

                $current = [
                    'dn' => trim(substr($line, 3)),
                    'values' => [],
                ];

                continue;
            }

            if (str_starts_with($line, $attribute.':')) {
                $current['values'][] = trim(substr($line, strlen($attribute) + 1));
            }
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function unfoldLdif(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') && $result !== []) {
                $result[count($result) - 1] .= substr($line, 1);
                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function normalizeContainerName(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return 'custom';
        }

        if (str_starts_with($value, 'cn=')) {
            $value = substr(explode(',', $value, 2)[0], 3);
        }

        return $value;
    }

    private function stripSchemaIndex(string $cn): string
    {
        return preg_replace('/^\{\d+\}/', '', trim($cn)) ?? trim($cn);
    }

    private function schemaIndex(string $cn): int
    {
        if (preg_match('/^\{(\d+)\}/', trim($cn), $matches)) {
            return (int) $matches[1];
        }

        return -1;
    }

    private function ldapUri($connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if ($host !== '' && str_contains($host, '://')) {
            return $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function value($model, array $columns, string $default = ''): string
    {
        foreach ($columns as $column) {
            if (isset($model->{$column}) && $model->{$column} !== null && $model->{$column} !== '') {
                return (string) $model->{$column};
            }
        }

        return $default;
    }

    private function redactedCommand(array $command): string
    {
        $copy = $command;

        foreach ($copy as $i => $part) {
            if ($part === '-w' && isset($copy[$i + 1])) {
                $copy[$i + 1] = '[REDACTED]';
            }
        }

        return implode(' ', array_map('escapeshellarg', $copy));
    }
}
