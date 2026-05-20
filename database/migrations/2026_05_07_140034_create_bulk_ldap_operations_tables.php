<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bulk_ldap_operations')) {
            Schema::create('bulk_ldap_operations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

                $table->string('name')->index();
                $table->string('operation_type')->index();
                $table->string('status')->default('draft')->index();

                $table->text('base_dn')->nullable();
                $table->text('target_ou_dn')->nullable();
                $table->text('destination_ou_dn')->nullable();

                $table->string('uid_prefix')->default('testuser')->index();
                $table->unsignedInteger('start_number')->default(1);
                $table->unsignedInteger('user_count')->default(1000);
                $table->unsignedInteger('number_padding')->default(4);

                $table->string('email_domain')->default('petra.ac.id');
                $table->string('display_name_prefix')->default('Bulk Test User');

                $table->json('default_object_classes')->nullable();
                $table->json('default_attributes')->nullable();
                $table->json('metadata')->nullable();

                $table->boolean('safe_mode')->default(true)->index();
                $table->boolean('dry_run')->default(true)->index();
                $table->boolean('destructive')->default(false)->index();
                $table->boolean('approval_required')->default(true)->index();

                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('pending_items')->default(0);
                $table->unsignedInteger('running_items')->default(0);
                $table->unsignedInteger('success_items')->default(0);
                $table->unsignedInteger('failed_items')->default(0);
                $table->unsignedInteger('skipped_items')->default(0);
                $table->unsignedInteger('already_applied_items')->default(0);
                $table->unsignedInteger('conflict_items')->default(0);
                $table->unsignedInteger('processed_items')->default(0);

                $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->nullOnDelete();

                $table->timestamp('previewed_at')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('failed_at')->nullable();

                $table->text('message')->nullable();
                $table->text('error_message')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                $table->index(['operation_type', 'status']);
                $table->index(['ldap_connection_id', 'status']);
                $table->index(['uid_prefix', 'status']);
                $table->index(['created_at', 'status']);
            });
        }

        if (! Schema::hasTable('bulk_ldap_operation_items')) {
            Schema::create('bulk_ldap_operation_items', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->foreignId('bulk_ldap_operation_id')->constrained('bulk_ldap_operations')->cascadeOnDelete();
                $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

                $table->unsignedInteger('sequence')->index();
                $table->string('action')->index();
                $table->string('status')->default('pending')->index();

                $table->string('uid')->index();
                $table->text('target_dn');
                $table->text('destination_dn')->nullable();

                $table->json('object_classes')->nullable();
                $table->json('attributes')->nullable();
                $table->json('before_value')->nullable();
                $table->json('after_value')->nullable();
                $table->json('metadata')->nullable();

                $table->string('payload_hash', 128)->index();

                $table->longText('ldif_preview')->nullable();
                $table->longText('stdout')->nullable();
                $table->longText('stderr')->nullable();
                $table->integer('exit_code')->nullable();
                $table->text('error_message')->nullable();

                $table->foreignId('command_execution_id')->nullable()->constrained('command_executions')->nullOnDelete();
                $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->nullOnDelete();

                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                $table->timestamps();

                $table->unique(['bulk_ldap_operation_id', 'sequence'], 'bulk_ldap_operation_items_operation_sequence_unique');
                $table->index(['bulk_ldap_operation_id', 'status']);
                $table->index(['bulk_ldap_operation_id', 'uid']);
                $table->index(['bulk_ldap_operation_id', 'action', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ldap_operation_items');
        Schema::dropIfExists('bulk_ldap_operations');
    }
};
