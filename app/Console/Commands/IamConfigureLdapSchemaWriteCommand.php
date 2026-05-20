<?php

namespace App\Console\Commands;

use App\Models\Directory\LdapConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class IamConfigureLdapSchemaWriteCommand extends Command
{
    protected $signature = 'iam:ldap-schema-write-config
        {--connection= : LDAP connection ID or name}
        {--enabled=1 : 1 enabled, 0 disabled}
        {--method=kubernetes_ldapi_external : disabled|simple_bind|kubernetes_ldapi_external}
        {--read-dn=cn=Subschema : Schema read DN}
        {--base=cn=schema,cn=config : Schema config base DN}
        {--container=petra : Logical schema container name}
        {--bind-dn= : Schema bind DN for simple_bind}
        {--bind-password= : Schema bind password for simple_bind}
        {--namespace= : Kubernetes namespace}
        {--pod= : Kubernetes pod name}
        {--selector= : Kubernetes pod selector}
        {--k8s-container= : Kubernetes container name}
        {--kubectl=microk8s kubectl : kubectl command}';

    protected $description = 'Configure schema write settings for an LDAP connection.';

    public function handle(): int
    {
        $connectionOption = (string) $this->option('connection');

        if ($connectionOption === '') {
            $this->error('--connection is required.');
            return self::FAILURE;
        }

        $connection = LdapConnection::query()
            ->where('id', $connectionOption)
            ->orWhere('name', $connectionOption)
            ->first();

        if (! $connection) {
            $this->error('LDAP connection not found: '.$connectionOption);
            return self::FAILURE;
        }

        $data = [
            'schema_write_enabled' => (string) $this->option('enabled') === '1',
            'schema_write_method' => (string) $this->option('method'),
            'schema_read_dn' => (string) $this->option('read-dn'),
            'schema_config_base_dn' => (string) $this->option('base'),
            'schema_container_name' => (string) $this->option('container'),
            'schema_bind_dn' => (string) $this->option('bind-dn') ?: null,
            'schema_bind_password' => (string) $this->option('bind-password') ?: null,
            'schema_k8s_namespace' => (string) $this->option('namespace') ?: null,
            'schema_k8s_pod' => (string) $this->option('pod') ?: null,
            'schema_k8s_pod_selector' => (string) $this->option('selector') ?: null,
            'schema_k8s_container' => (string) $this->option('k8s-container') ?: null,
            'schema_k8s_kubectl' => (string) $this->option('kubectl') ?: 'microk8s kubectl',
        ];

        foreach ($data as $column => $value) {
            if (Schema::hasColumn('ldap_connections', $column)) {
                $connection->{$column} = $value;
            }
        }

        $connection->save();

        $this->info('Schema write config updated for LDAP connection: '.$connection->id.' - '.$connection->name);
        $this->line('Method: '.$connection->schema_write_method);
        $this->line('Enabled: '.($connection->schema_write_enabled ? 'yes' : 'no'));
        $this->line('Base DN: '.$connection->schema_config_base_dn);
        $this->line('Container: '.$connection->schema_container_name);
        $this->line('K8s namespace: '.$connection->schema_k8s_namespace);
        $this->line('K8s pod: '.$connection->schema_k8s_pod);
        $this->line('K8s container: '.$connection->schema_k8s_container);

        return self::SUCCESS;
    }
}
