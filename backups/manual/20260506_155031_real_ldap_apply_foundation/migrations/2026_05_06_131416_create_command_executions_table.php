<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('command_executions')) {
            return;
        }

        Schema::create('command_executions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->ipAddress('actor_ip')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('module')->default('operations.command');
            $table->string('command_type')->default('safe_artisan')->index();
            $table->string('status')->default('pending')->index();

            $table->longText('command');
            $table->string('working_directory')->nullable();
            $table->json('environment_context')->nullable();

            $table->boolean('safe_mode')->default(true)->index();
            $table->boolean('preview_mode')->default(false)->index();
            $table->boolean('destructive')->default(false)->index();

            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
            $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->nullOnDelete();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['module', 'command_type', 'status']);
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_executions');
    }
};
