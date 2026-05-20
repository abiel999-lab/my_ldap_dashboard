<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_apply_plans')) {
            return;
        }

        Schema::table('import_apply_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_apply_plans', 'real_apply_started_at')) {
                $table->timestamp('real_apply_started_at')->nullable()->index()->after('dry_run_error_message');
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_finished_at')) {
                $table->timestamp('real_apply_finished_at')->nullable()->index()->after('real_apply_started_at');
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_by')) {
                $table->foreignId('real_apply_by')->nullable()->after('real_apply_finished_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_command_execution_id')) {
                $table->foreignId('real_apply_command_execution_id')->nullable()->after('real_apply_by')->constrained('command_executions')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_output_summary')) {
                $table->text('real_apply_output_summary')->nullable()->after('real_apply_command_execution_id');
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_error_message')) {
                $table->text('real_apply_error_message')->nullable()->after('real_apply_output_summary');
            }

            if (! Schema::hasColumn('import_apply_plans', 'real_apply_confirmation')) {
                $table->string('real_apply_confirmation')->nullable()->after('real_apply_error_message');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted.
    }
};
