<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_unit_entries')) {
            return;
        }

        Schema::create('ldap_unit_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->foreignId('ldap_group_entry_id')->nullable()->constrained('ldap_group_entries')->nullOnDelete();

            $table->text('dn')->index();
            $table->text('parent_dn')->nullable()->index();

            $table->string('entry_uuid')->nullable()->index();
            $table->string('ou')->nullable()->index();
            $table->string('unit_key')->nullable()->index();
            $table->string('unit_name')->nullable()->index();
            $table->string('unit_type')->nullable()->index();
            $table->unsignedInteger('tree_level')->default(0)->index();

            $table->unsignedInteger('direct_child_count')->default(0)->index();
            $table->unsignedInteger('user_count')->default(0)->index();
            $table->unsignedInteger('group_count')->default(0)->index();

            $table->string('source')->default('ldap_ou')->index();
            $table->string('status')->default('active')->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('child_unit_dns')->nullable();
            $table->json('metadata')->nullable();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'unit_key']);
            $table->index(['unit_type', 'status']);
            $table->index(['tree_level', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_unit_entries');
    }
};
