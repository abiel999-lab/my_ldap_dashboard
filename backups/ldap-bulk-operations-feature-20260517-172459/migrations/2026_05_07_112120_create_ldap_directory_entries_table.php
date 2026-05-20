<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_directory_entries')) {
            return;
        }

        Schema::create('ldap_directory_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->foreignId('ldap_entry_type_rule_id')->nullable()->constrained('ldap_entry_type_rules')->nullOnDelete();

            $table->text('dn')->index();
            $table->text('parent_dn')->nullable()->index();

            $table->string('rdn')->nullable()->index();
            $table->string('rdn_attribute')->nullable()->index();
            $table->string('rdn_value')->nullable()->index();

            $table->string('entry_uuid')->nullable()->index();
            $table->string('entry_type')->default('generic_entry')->index();
            $table->string('entry_category')->nullable()->index();

            $table->string('identifier_attribute')->nullable();
            $table->string('identifier_value')->nullable()->index();
            $table->string('display_attribute')->nullable();
            $table->string('display_value')->nullable()->index();
            $table->string('email_attribute')->nullable();
            $table->string('email_value')->nullable()->index();

            $table->unsignedInteger('tree_level')->default(0)->index();
            $table->unsignedInteger('child_count')->default(0)->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('operational_attributes')->nullable();
            $table->json('metadata')->nullable();

            $table->string('source')->default('ldap_search')->index();
            $table->string('status')->default('active')->index();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'entry_type']);
            $table->index(['entry_type', 'status']);
            $table->index(['entry_category', 'status']);
            $table->index(['tree_level', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_directory_entries');
    }
};
