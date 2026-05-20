<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_apply_plans')) {
            return;
        }

        Schema::create('import_apply_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->string('name')->index();
            $table->string('status')->default('draft')->index();
            $table->string('plan_type')->default('ldif_dry_run')->index();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('planned_create_rows')->default(0);
            $table->unsignedInteger('planned_update_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            $table->string('output_disk')->default('local');
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->string('output_hash')->nullable();

            $table->boolean('safe_mode')->default(true)->index();
            $table->boolean('dry_run')->default(true)->index();
            $table->boolean('destructive')->default(false)->index();

            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_apply_plans');
    }
};
