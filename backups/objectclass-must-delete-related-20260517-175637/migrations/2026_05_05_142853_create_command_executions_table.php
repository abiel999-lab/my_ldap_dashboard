<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_executions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
            $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->nullOnDelete();
            $table->string('command_type')->index();
            $table->longText('command');
            $table->string('working_directory')->nullable();
            $table->json('environment_context')->nullable();
            $table->longText('stdin')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('is_preview')->default(false);
            $table->boolean('is_safe_mode')->default(true);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['command_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_executions');
    }
};
