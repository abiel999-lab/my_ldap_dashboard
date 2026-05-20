<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('command_executions')) {
            Schema::create('command_executions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name')->nullable();
                $table->string('actor_email')->nullable();
                $table->ipAddress('actor_ip')->nullable();
                $table->text('user_agent')->nullable();

                $table->string('module')->default('operations.command')->index();
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

            return;
        }

        Schema::table('command_executions', function (Blueprint $table): void {
            if (! Schema::hasColumn('command_executions', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('command_executions', 'actor_user_id')) {
                $table->foreignId('actor_user_id')->nullable()->after('uuid')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('command_executions', 'actor_name')) {
                $table->string('actor_name')->nullable()->after('actor_user_id');
            }

            if (! Schema::hasColumn('command_executions', 'actor_email')) {
                $table->string('actor_email')->nullable()->after('actor_name');
            }

            if (! Schema::hasColumn('command_executions', 'actor_ip')) {
                $table->ipAddress('actor_ip')->nullable()->after('actor_email');
            }

            if (! Schema::hasColumn('command_executions', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('actor_ip');
            }

            if (! Schema::hasColumn('command_executions', 'module')) {
                $table->string('module')->default('operations.command')->index()->after('user_agent');
            }

            if (! Schema::hasColumn('command_executions', 'command_type')) {
                $table->string('command_type')->default('safe_artisan')->index()->after('module');
            }

            if (! Schema::hasColumn('command_executions', 'status')) {
                $table->string('status')->default('pending')->index()->after('command_type');
            }

            if (! Schema::hasColumn('command_executions', 'command')) {
                $table->longText('command')->nullable()->after('status');
            }

            if (! Schema::hasColumn('command_executions', 'working_directory')) {
                $table->string('working_directory')->nullable()->after('command');
            }

            if (! Schema::hasColumn('command_executions', 'environment_context')) {
                $table->json('environment_context')->nullable()->after('working_directory');
            }

            if (! Schema::hasColumn('command_executions', 'safe_mode')) {
                $table->boolean('safe_mode')->default(true)->index()->after('environment_context');
            }

            if (! Schema::hasColumn('command_executions', 'preview_mode')) {
                $table->boolean('preview_mode')->default(false)->index()->after('safe_mode');
            }

            if (! Schema::hasColumn('command_executions', 'destructive')) {
                $table->boolean('destructive')->default(false)->index()->after('preview_mode');
            }

            if (! Schema::hasColumn('command_executions', 'stdout')) {
                $table->longText('stdout')->nullable()->after('destructive');
            }

            if (! Schema::hasColumn('command_executions', 'stderr')) {
                $table->longText('stderr')->nullable()->after('stdout');
            }

            if (! Schema::hasColumn('command_executions', 'exit_code')) {
                $table->integer('exit_code')->nullable()->after('stderr');
            }

            if (! Schema::hasColumn('command_executions', 'error_message')) {
                $table->text('error_message')->nullable()->after('exit_code');
            }

            if (! Schema::hasColumn('command_executions', 'operation_job_id')) {
                $table->foreignId('operation_job_id')->nullable()->after('error_message')->constrained('operation_jobs')->nullOnDelete();
            }

            if (! Schema::hasColumn('command_executions', 'operation_job_item_id')) {
                $table->foreignId('operation_job_item_id')->nullable()->after('operation_job_id')->constrained('operation_job_items')->nullOnDelete();
            }

            if (! Schema::hasColumn('command_executions', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('operation_job_item_id');
            }

            if (! Schema::hasColumn('command_executions', 'started_at')) {
                $table->timestamp('started_at')->nullable()->index()->after('duration_ms');
            }

            if (! Schema::hasColumn('command_executions', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->index()->after('started_at');
            }

            if (! Schema::hasColumn('command_executions', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
