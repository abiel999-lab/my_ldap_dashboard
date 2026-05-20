<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_user_entries')) {
            return;
        }

        Schema::create('ldap_user_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->text('dn')->index();
            $table->string('entry_uuid')->nullable()->index();

            $table->string('uid')->nullable()->index();
            $table->string('cn')->nullable()->index();
            $table->string('sn')->nullable()->index();
            $table->string('given_name')->nullable()->index();
            $table->string('display_name')->nullable()->index();
            $table->string('mail')->nullable()->index();
            $table->string('employee_number')->nullable()->index();
            $table->string('employee_type')->nullable()->index();

            $table->string('status')->default('active')->index();
            $table->boolean('is_disabled')->default(false)->index();
            $table->boolean('is_locked')->default(false)->index();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('operational_attributes')->nullable();
            $table->json('group_dns')->nullable();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
            $table->index(['ldap_connection_id', 'uid']);
            $table->index(['ldap_connection_id', 'mail']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_user_entries');
    }
};
