<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_application_entries')) {
            return;
        }

        Schema::create('ldap_application_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->foreignId('ldap_group_entry_id')->nullable()->constrained('ldap_group_entries')->nullOnDelete();

            $table->text('dn')->index();
            $table->string('entry_uuid')->nullable()->index();

            $table->string('app_key')->nullable()->index();
            $table->string('app_name')->nullable()->index();
            $table->string('cn')->nullable()->index();
            $table->string('application_type')->default('ldap_app_group')->index();
            $table->string('integration_type')->nullable()->index();
            $table->string('environment')->nullable()->index();
            $table->string('description')->nullable();

            $table->unsignedInteger('allowed_group_count')->default(0)->index();
            $table->unsignedInteger('required_role_count')->default(0)->index();
            $table->unsignedInteger('resolved_user_count')->default(0)->index();

            $table->json('allowed_group_dns')->nullable();
            $table->json('required_role_ids')->nullable();
            $table->json('required_role_keys')->nullable();
            $table->json('resolved_user_ids')->nullable();

            $table->boolean('oidc_enabled')->default(false)->index();
            $table->boolean('saml_enabled')->default(false)->index();
            $table->boolean('api_access_enabled')->default(false)->index();

            $table->string('source')->default('ldap_group')->index();
            $table->string('status')->default('active')->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('metadata')->nullable();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'app_key']);
            $table->index(['application_type', 'status']);
            $table->index(['integration_type', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_application_entries');
    }
};
