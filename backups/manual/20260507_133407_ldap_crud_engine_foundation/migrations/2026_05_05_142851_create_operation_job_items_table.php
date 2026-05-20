<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_job_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_job_id')->constrained('operation_jobs')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sequence')->default(0)->index();
            $table->string('target_type')->nullable()->index();
            $table->string('target_key')->nullable()->index();
            $table->foreignId('target_ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();
            $table->text('target_dn')->nullable();
            $table->string('action')->index();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->string('payload_hash')->nullable()->index();
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->longText('last_stdout')->nullable();
            $table->longText('last_stderr')->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['operation_job_id', 'status']);
            $table->index(['target_ldap_connection_id', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_job_items');
    }
};
