<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_servers')) {
            return;
        }

        Schema::create('ldap_servers', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            $table->string('organization')->nullable();
            $table->string('domain')->nullable();

            $table->string('base_dn');
            $table->string('admin_rdn')->default('cn=admin');
            $table->string('admin_dn');
            $table->text('admin_password')->nullable();

            $table->string('host')->default('127.0.0.1');
            $table->unsignedInteger('ldap_port')->default(389);
            $table->unsignedInteger('ldaps_port')->nullable();

            $table->string('scheme')->default('ldap');
            $table->string('provision_mode')->default('docker');
            $table->string('expose_mode')->default('local');

            $table->string('container_name')->nullable();
            $table->string('docker_image')->default('osixia/openldap:1.5.0');

            $table->string('status')->default('draft');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_registered_connection')->default(false);

            $table->text('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();

            $table->longText('docker_command')->nullable();
            $table->longText('docker_compose_yaml')->nullable();
            $table->longText('kubernetes_manifest')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['status', 'is_active']);
            $table->index(['host', 'ldap_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_servers');
    }
};
