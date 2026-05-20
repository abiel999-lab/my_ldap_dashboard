<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldif_export_batches')) {
            return;
        }

        Schema::create('ldif_export_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name')->index();
            $table->string('status')->default('draft')->index();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->text('base_dn');
            $table->string('filter')->default('(objectClass=*)');
            $table->text('attributes')->nullable();
            $table->unsignedInteger('size_limit')->default(500);

            $table->string('output_disk')->default('local');
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->string('output_hash')->nullable();

            $table->boolean('safe_mode')->default(true)->index();
            $table->boolean('preview_mode')->default(false)->index();
            $table->boolean('destructive')->default(false)->index();

            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();
            $table->foreignId('command_execution_id')->nullable()->constrained('command_executions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['ldap_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldif_export_batches');
    }
};
