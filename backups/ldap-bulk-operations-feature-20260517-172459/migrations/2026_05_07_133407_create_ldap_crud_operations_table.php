<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_crud_operations')) {
            return;
        }

        Schema::create('ldap_crud_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->string('name')->index();
            $table->string('operation_type')->index();
            $table->string('status')->default('draft')->index();

            $table->text('target_dn')->nullable();
            $table->text('new_dn')->nullable();
            $table->text('parent_dn')->nullable();
            $table->string('new_rdn')->nullable();

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('metadata')->nullable();

            $table->longText('ldif_preview')->nullable();
            $table->longText('command_preview')->nullable();

            $table->boolean('safe_mode')->default(true)->index();
            $table->boolean('dry_run')->default(true)->index();
            $table->boolean('destructive')->default(false)->index();
            $table->boolean('approval_required')->default(true)->index();

            $table->foreignId('preview_command_execution_id')->nullable()->constrained('command_executions')->nullOnDelete();
            $table->foreignId('apply_command_execution_id')->nullable()->constrained('command_executions')->nullOnDelete();
            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();

            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->text('message')->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['operation_type', 'status']);
            $table->index(['ldap_connection_id', 'status']);
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_crud_operations');
    }
};
