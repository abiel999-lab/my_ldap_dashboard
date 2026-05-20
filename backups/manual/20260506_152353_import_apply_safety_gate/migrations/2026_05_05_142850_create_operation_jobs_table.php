<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('module')->index();
            $table->string('operation_type')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('queue_name')->default('default')->index();
            $table->unsignedBigInteger('total_items')->default(0);
            $table->unsignedBigInteger('pending_items')->default(0);
            $table->unsignedBigInteger('running_items')->default(0);
            $table->unsignedBigInteger('success_items')->default(0);
            $table->unsignedBigInteger('failed_items')->default(0);
            $table->unsignedBigInteger('skipped_items')->default(0);
            $table->unsignedBigInteger('conflict_items')->default(0);
            $table->unsignedInteger('progress_percent')->default(0);
            $table->json('payload')->nullable();
            $table->json('preview_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['module', 'operation_type', 'status']);
            $table->index(['status', 'queue_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_jobs');
    }
};
