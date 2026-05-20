<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LdapServerProvisioningService
{
    public function normalizePayload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? 'LDAP Server'));
        $slug = Str::slug((string) ($data['slug'] ?? $name));

        $domain = trim((string) ($data['domain'] ?? 'test.local'));
        $baseDn = trim((string) ($data['base_dn'] ?? ''));

        if ($baseDn === '') {
            $baseDn = $this->domainToBaseDn($domain);
        }

        $adminRdn = trim((string) ($data['admin_rdn'] ?? 'cn=admin'));
        $adminDn = trim((string) ($data['admin_dn'] ?? ''));

        if ($adminDn === '') {
            $adminDn = $adminRdn.','.$baseDn;
        }

        $data['name'] = $name;
        $data['slug'] = $slug;
        $data['domain'] = $domain;
        $data['base_dn'] = $baseDn;
        $data['admin_rdn'] = $adminRdn;
        $data['admin_dn'] = $adminDn;
        $data['container_name'] = $data['container_name'] ?? 'openldap-'.$slug;
        $data['docker_image'] = $data['docker_image'] ?? 'osixia/openldap:1.5.0';
        $data['scheme'] = $data['scheme'] ?? 'ldap';
        $data['provision_mode'] = $data['provision_mode'] ?? 'docker';
        $data['expose_mode'] = $data['expose_mode'] ?? 'local';
        $data['host'] = $data['host'] ?? '127.0.0.1';
        $data['ldap_port'] = (int) ($data['ldap_port'] ?? 389);

        return $data;
    }

    public function refreshGeneratedArtifacts(LdapServer $server): LdapServer
    {
        $server->forceFill([
            'docker_command' => $this->dockerCommand($server),
            'docker_compose_yaml' => $this->dockerComposeYaml($server),
            'kubernetes_manifest' => $this->kubernetesManifest($server),
        ])->save();

        return $server->refresh();
    }

    public function dockerCommand(LdapServer $server): string
    {
        $domain = $server->domain ?: 'test.local';
        $organization = $server->organization ?: $server->name;
        $password = $server->admin_password ?: 'CHANGE_ME_STRONG_PASSWORD';
        $container = $server->container_name ?: 'openldap-'.$server->safeName();
        $image = $server->docker_image ?: 'osixia/openldap:1.5.0';
        $port = $server->ldap_port ?: 389;

        return implode(" \\\n  ", [
            'docker run -d',
            '--name '.escapeshellarg($container),
            '-p '.escapeshellarg($port.':389'),
            '-e LDAP_ORGANISATION='.escapeshellarg($organization),
            '-e LDAP_DOMAIN='.escapeshellarg($domain),
            '-e LDAP_ADMIN_PASSWORD='.escapeshellarg($password),
            '-e LDAP_TLS=false',
            '--restart unless-stopped',
            escapeshellarg($image),
        ]);
    }

    public function dockerComposeYaml(LdapServer $server): string
    {
        $container = $server->container_name ?: 'openldap-'.$server->safeName();
        $image = $server->docker_image ?: 'osixia/openldap:1.5.0';
        $domain = $server->domain ?: 'test.local';
        $organization = $server->organization ?: $server->name;
        $password = $server->admin_password ?: 'CHANGE_ME_STRONG_PASSWORD';
        $port = $server->ldap_port ?: 389;

        return <<<YAML
services:
  {$container}:
    image: {$image}
    container_name: {$container}
    restart: unless-stopped
    ports:
      - "{$port}:389"
    environment:
      LDAP_ORGANISATION: "{$organization}"
      LDAP_DOMAIN: "{$domain}"
      LDAP_ADMIN_PASSWORD: "{$password}"
      LDAP_TLS: "false"
    volumes:
      - {$container}_data:/var/lib/ldap
      - {$container}_config:/etc/ldap/slapd.d

volumes:
  {$container}_data:
  {$container}_config:
YAML;
    }

    public function kubernetesManifest(LdapServer $server): string
    {
        $name = $server->safeName();
        $image = $server->docker_image ?: 'osixia/openldap:1.5.0';
        $domain = $server->domain ?: 'test.local';
        $organization = $server->organization ?: $server->name;
        $password = $server->admin_password ?: 'CHANGE_ME_STRONG_PASSWORD';
        $port = $server->ldap_port ?: 389;

        return <<<YAML
apiVersion: v1
kind: Secret
metadata:
  name: {$name}-secret
type: Opaque
stringData:
  LDAP_ADMIN_PASSWORD: "{$password}"
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {$name}-data
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 1Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {$name}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {$name}
  template:
    metadata:
      labels:
        app: {$name}
    spec:
      containers:
        - name: openldap
          image: {$image}
          ports:
            - containerPort: 389
          env:
            - name: LDAP_ORGANISATION
              value: "{$organization}"
            - name: LDAP_DOMAIN
              value: "{$domain}"
            - name: LDAP_TLS
              value: "false"
            - name: LDAP_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {$name}-secret
                  key: LDAP_ADMIN_PASSWORD
          volumeMounts:
            - name: ldap-data
              mountPath: /var/lib/ldap
      volumes:
        - name: ldap-data
          persistentVolumeClaim:
            claimName: {$name}-data
---
apiVersion: v1
kind: Service
metadata:
  name: {$name}
spec:
  type: ClusterIP
  selector:
    app: {$name}
  ports:
    - name: ldap
      port: {$port}
      targetPort: 389
YAML;
    }

    public function testConnection(LdapServer $server): array
    {
        $endpoint = $server->endpoint();

        if (! function_exists('ldap_connect')) {
            return [
                'ok' => false,
                'message' => 'PHP LDAP extension is not installed/enabled.',
            ];
        }

        try {
            $ldap = @ldap_connect($endpoint);

            if (! $ldap) {
                return [
                    'ok' => false,
                    'message' => 'Cannot create LDAP connection handle.',
                ];
            }

            @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

            $bind = @ldap_bind($ldap, $server->admin_dn, (string) $server->admin_password);

            if (! $bind) {
                $error = @ldap_error($ldap) ?: 'LDAP bind failed.';

                return [
                    'ok' => false,
                    'message' => $error,
                ];
            }

            @ldap_unbind($ldap);

            return [
                'ok' => true,
                'message' => 'LDAP bind success: '.$endpoint,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function registerAsLdapConnection(LdapServer $server): array
    {
        if (! Schema::hasTable('ldap_connections')) {
            return [
                'ok' => false,
                'message' => 'Table ldap_connections does not exist.',
            ];
        }

        $table = 'ldap_connections';

        $baseDn = $server->base_dn;
        $userBaseDn = 'ou=people,'.$baseDn;
        $groupBaseDn = 'ou=groups,'.$baseDn;

        $payload = [
            'uuid' => (string) Str::uuid(),
            'name' => $server->name,
            'environment_label' => $server->provision_mode.' / '.$server->expose_mode,
            'host' => $server->host,
            'port' => $server->ldap_port,
            'base_dn' => $baseDn,
            'bind_dn' => $server->admin_dn,
            'bind_password' => $server->admin_password,
            'use_ssl' => $server->scheme === 'ldaps',
            'use_tls' => false,
            'timeout' => 5,
            'is_active' => true,
            'is_default' => false,
            'is_read_only' => false,
            'user_base_dn' => $userBaseDn,
            'group_base_dn' => $groupBaseDn,
            'user_identifier_attribute' => 'uid',
            'user_display_attribute' => 'cn',
            'user_email_attribute' => 'mail',
            'group_member_attribute' => 'member',
            'uuid_attribute' => 'entryUUID',
            'attribute_mapping' => json_encode([
                'uid' => 'uid',
                'cn' => 'cn',
                'sn' => 'sn',
                'mail' => 'mail',
            ]),
            'metadata' => json_encode([
                'source' => 'ldap_server_provisioning',
                'ldap_server_id' => $server->id,
                'container_name' => $server->container_name,
                'docker_image' => $server->docker_image,
                'endpoint' => $server->endpoint(),
            ]),
            'last_health_checked_at' => now(),
            'last_health_status' => $server->last_test_status ?: 'unknown',
            'last_health_message' => $server->last_error ?: 'Generated from LDAP Server Provisioning.',
            'schema_write_enabled' => false,
            'schema_write_method' => 'none',
            'schema_read_dn' => 'cn=Subschema',
            'schema_config_base_dn' => 'cn=config',
            'schema_container_name' => $server->container_name,
            'schema_bind_dn' => $server->admin_dn,
            'schema_bind_password' => $server->admin_password,
            'schema_k8s_namespace' => null,
            'schema_k8s_pod' => null,
            'schema_k8s_pod_selector' => null,
            'schema_k8s_container' => null,
            'schema_k8s_kubectl' => 'microk8s kubectl',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insert = [];

        foreach ($payload as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $insert[$column] = $value;
            }
        }

        $existing = DB::table($table)
            ->where('host', $server->host)
            ->where('port', $server->ldap_port)
            ->where('base_dn', $server->base_dn)
            ->first();

        if ($existing) {
            unset($insert['uuid'], $insert['created_at']);

            $insert['updated_at'] = now();

            DB::table($table)
                ->where('id', $existing->id)
                ->update($insert);

            $server->forceFill(['is_registered_connection' => true])->save();

            return [
                'ok' => true,
                'message' => 'Existing LDAP Connection updated.',
            ];
        }

        DB::table($table)->insert($insert);

        $server->forceFill(['is_registered_connection' => true])->save();

        return [
            'ok' => true,
            'message' => 'LDAP Connection created.',
        ];
    }


    public function startDockerContainer(LdapServer $server): array
    {
        $container = $server->container_name ?: 'openldap-'.$server->safeName();

        $exists = $this->runProcess(['docker', 'ps', '-a', '--format', '{{.Names}}']);
        if (! $exists['ok']) {
            return $exists;
        }

        $names = preg_split('/\R/', trim($exists['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        if (in_array($container, $names, true)) {
            $start = $this->runProcess(['docker', 'start', $container]);

            if ($start['ok']) {
                $server->forceFill([
                    'status' => 'running',
                    'last_error' => null,
                ])->save();
            }

            return $start;
        }

        $image = $server->docker_image ?: 'osixia/openldap:1.5.0';
        $port = (string) ($server->ldap_port ?: 389);
        $organization = $server->organization ?: $server->name;
        $domain = $server->domain ?: 'test.local';
        $password = $server->admin_password ?: 'CHANGE_ME_STRONG_PASSWORD';

        $run = $this->runProcess([
            'docker', 'run', '-d',
            '--name', $container,
            '-p', $port.':389',
            '-e', 'LDAP_ORGANISATION='.$organization,
            '-e', 'LDAP_DOMAIN='.$domain,
            '-e', 'LDAP_ADMIN_PASSWORD='.$password,
            '-e', 'LDAP_TLS=false',
            '--restart', 'unless-stopped',
            $image,
        ], 60);

        $server->forceFill([
            'status' => $run['ok'] ? 'running' : 'error',
            'last_error' => $run['ok'] ? null : $run['message'],
        ])->save();

        return $run;
    }

    public function stopDockerContainer(LdapServer $server): array
    {
        $container = $server->container_name ?: 'openldap-'.$server->safeName();

        $result = $this->runProcess(['docker', 'stop', $container]);

        $server->forceFill([
            'status' => $result['ok'] ? 'stopped' : 'error',
            'last_error' => $result['ok'] ? null : $result['message'],
        ])->save();

        return $result;
    }

    public function restartDockerContainer(LdapServer $server): array
    {
        $container = $server->container_name ?: 'openldap-'.$server->safeName();

        $result = $this->runProcess(['docker', 'restart', $container]);

        $server->forceFill([
            'status' => $result['ok'] ? 'running' : 'error',
            'last_error' => $result['ok'] ? null : $result['message'],
        ])->save();

        return $result;
    }

    public function checkDockerContainer(LdapServer $server): array
    {
        $container = $server->container_name ?: 'openldap-'.$server->safeName();

        $result = $this->runProcess([
            'docker', 'inspect',
            '--format',
            '{{.State.Status}}',
            $container,
        ]);

        if ($result['ok']) {
            $status = trim((string) $result['output']);

            $server->forceFill([
                'status' => $status ?: 'unknown',
                'last_error' => null,
            ])->save();

            return [
                'ok' => true,
                'message' => 'Docker container status: '.($status ?: 'unknown'),
                'output' => $status,
            ];
        }

        $server->forceFill([
            'status' => 'not_found',
            'last_error' => $result['message'],
        ])->save();

        return $result;
    }

    private function runProcess(array $command, int $timeout = 30): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();

            $output = trim($process->getOutput());
            $error = trim($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                return [
                    'ok' => false,
                    'message' => $error ?: $output ?: 'Command failed.',
                    'output' => $output,
                ];
            }

            return [
                'ok' => true,
                'message' => $output ?: 'Command executed successfully.',
                'output' => $output,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'output' => null,
            ];
        }
    }


    private function domainToBaseDn(string $domain): string
    {
        $parts = array_filter(explode('.', strtolower(trim($domain))));

        if ($parts === []) {
            return 'dc=test,dc=local';
        }

        return collect($parts)
            ->map(fn (string $part): string => 'dc='.preg_replace('/[^a-z0-9-]/', '', $part))
            ->implode(',');
    }
}
