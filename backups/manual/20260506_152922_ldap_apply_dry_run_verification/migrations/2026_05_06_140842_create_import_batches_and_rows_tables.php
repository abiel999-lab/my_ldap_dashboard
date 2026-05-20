<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->string('name')->index();
                $table->string('import_type')->default('csv')->index();
                $table->string('status')->default('draft')->index();

                $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

                $table->string('file_disk')->default('local');
                $table->string('file_path')->nullable();
                $table->string('original_filename')->nullable();
                $table->unsignedBigInteger('file_size_bytes')->nullable();
                $table->string('file_hash')->nullable();

                $table->text('base_dn')->nullable();
                $table->string('identifier_attribute')->default('uid');
                $table->string('dn_template')->nullable();

                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('invalid_rows')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('conflict_rows')->default(0);
                $table->unsignedInteger('will_create_rows')->default(0);
                $table->unsignedInteger('will_update_rows')->default(0);
                $table->unsignedInteger('will_skip_rows')->default(0);
                $table->unsignedInteger('will_fail_rows')->default(0);

                $table->boolean('safe_mode')->default(true)->index();
                $table->boolean('preview_only')->default(true)->index();
                $table->boolean('destructive')->default(false)->index();

                $table->text('message')->nullable();
                $table->json('metadata')->nullable();

                $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamp('preview_started_at')->nullable()->index();
                $table->timestamp('preview_finished_at')->nullable()->index();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['import_type', 'status']);
                $table->index(['ldap_connection_id', 'status']);
            });
        }

        if (! Schema::hasTable('import_rows')) {
            Schema::create('import_rows', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
                $table->unsignedInteger('row_number')->index();

                $table->string('status')->default('pending')->index();
                $table->string('action_plan')->default('skip')->index();
                $table->text('target_dn')->nullable()->index();
                $table->string('target_identifier')->nullable()->index();

                $table->json('raw_payload')->nullable();
                $table->json('mapped_payload')->nullable();
                $table->json('validation_errors')->nullable();
                $table->json('warnings')->nullable();

                $table->string('payload_hash')->nullable()->index();
                $table->text('conflict_reason')->nullable();
                $table->text('message')->nullable();

                $table->timestamps();

                $table->unique(['import_batch_id', 'row_number']);
                $table->index(['import_batch_id', 'status']);
                $table->index(['import_batch_id', 'action_plan']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
