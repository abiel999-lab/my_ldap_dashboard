<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->ipAddress('actor_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('module')->index();
            $table->string('action')->index();
            $table->string('status')->default('success')->index();
            $table->string('target_type')->nullable()->index();
            $table->string('target_key')->nullable()->index();
            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->text('target_dn')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->longText('command')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
            $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->nullOnDelete();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'action', 'status']);
            $table->index(['created_at', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
