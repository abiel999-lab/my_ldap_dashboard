<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ldap_connections')) {
            return;
        }

        Schema::table('ldap_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('ldap_connections', 'schema_write_enabled')) {
                $table->boolean('schema_write_enabled')->default(false)->index();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_write_method')) {
                $table->string('schema_write_method')->default('disabled')->index();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_read_dn')) {
                $table->string('schema_read_dn')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_config_base_dn')) {
                $table->string('schema_config_base_dn')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_container_name')) {
                $table->string('schema_container_name')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_bind_dn')) {
                $table->string('schema_bind_dn')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_bind_password')) {
                $table->text('schema_bind_password')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_k8s_namespace')) {
                $table->string('schema_k8s_namespace')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_k8s_pod')) {
                $table->string('schema_k8s_pod')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_k8s_pod_selector')) {
                $table->string('schema_k8s_pod_selector')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_k8s_container')) {
                $table->string('schema_k8s_container')->nullable();
            }

            if (! Schema::hasColumn('ldap_connections', 'schema_k8s_kubectl')) {
                $table->string('schema_k8s_kubectl')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Safe migration: keep columns to avoid losing connection configuration.
    }
};
