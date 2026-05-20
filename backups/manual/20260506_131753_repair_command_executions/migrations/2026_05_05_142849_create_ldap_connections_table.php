<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ldap_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('environment_label')->default('local')->index();
            $table->string('host');
            $table->unsignedInteger('port')->default(389);
            $table->string('base_dn');
            $table->string('bind_dn')->nullable();
            $table->text('bind_password')->nullable();
            $table->boolean('use_ssl')->default(false);
            $table->boolean('use_tls')->default(false);
            $table->unsignedInteger('timeout')->default(5);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_read_only')->default(false);
            $table->string('user_base_dn')->nullable();
            $table->string('group_base_dn')->nullable();
            $table->string('user_identifier_attribute')->default('uid');
            $table->string('user_display_attribute')->default('cn');
            $table->string('user_email_attribute')->default('mail');
            $table->string('group_member_attribute')->default('member');
            $table->string('uuid_attribute')->default('entryUUID');
            $table->json('attribute_mapping')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_health_checked_at')->nullable();
            $table->string('last_health_status')->nullable()->index();
            $table->text('last_health_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host', 'port']);
            $table->index(['is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_connections');
    }
};
