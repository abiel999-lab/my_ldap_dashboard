<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_group_entries')) {
            return;
        }

        Schema::create('ldap_group_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->text('dn')->index();
            $table->string('entry_uuid')->nullable()->index();

            $table->string('cn')->nullable()->index();
            $table->string('ou')->nullable()->index();
            $table->string('description')->nullable();
            $table->string('group_type')->nullable()->index();

            $table->unsignedInteger('member_count')->default(0)->index();
            $table->unsignedInteger('nested_group_count')->default(0)->index();

            $table->string('status')->default('active')->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('operational_attributes')->nullable();
            $table->json('member_dns')->nullable();
            $table->json('member_uids')->nullable();
            $table->json('nested_group_dns')->nullable();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'cn']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_group_entries');
    }
};
