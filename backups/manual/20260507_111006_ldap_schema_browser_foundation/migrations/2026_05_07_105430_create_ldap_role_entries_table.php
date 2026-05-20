<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_role_entries')) {
            return;
        }

        Schema::create('ldap_role_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->foreignId('ldap_group_entry_id')->nullable()->constrained('ldap_group_entries')->nullOnDelete();

            $table->text('dn')->index();
            $table->string('entry_uuid')->nullable()->index();

            $table->string('cn')->nullable()->index();
            $table->string('role_key')->nullable()->index();
            $table->string('role_name')->nullable()->index();
            $table->string('role_type')->nullable()->index();
            $table->string('role_scope')->nullable()->index();
            $table->string('application_key')->nullable()->index();
            $table->string('description')->nullable();

            $table->unsignedInteger('member_count')->default(0)->index();
            $table->unsignedInteger('resolved_user_count')->default(0)->index();

            $table->string('source')->default('ldap_group')->index();
            $table->string('status')->default('active')->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('member_dns')->nullable();
            $table->json('member_uids')->nullable();
            $table->json('resolved_user_ids')->nullable();
            $table->json('metadata')->nullable();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'role_key']);
            $table->index(['role_type', 'status']);
            $table->index(['role_scope', 'application_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_role_entries');
    }
};
