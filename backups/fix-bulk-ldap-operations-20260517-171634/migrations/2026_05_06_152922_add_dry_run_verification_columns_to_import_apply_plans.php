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
            if (! Schema::hasColumn('import_apply_plans', 'dry_run_verified_at')) {
                $table->timestamp('dry_run_verified_at')->nullable()->index()->after('approved_at');
            }

            if (! Schema::hasColumn('import_apply_plans', 'dry_run_verified_by')) {
                $table->foreignId('dry_run_verified_by')->nullable()->after('dry_run_verified_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'dry_run_command_execution_id')) {
                $table->foreignId('dry_run_command_execution_id')->nullable()->after('dry_run_verified_by')->constrained('command_executions')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'dry_run_output_summary')) {
                $table->text('dry_run_output_summary')->nullable()->after('dry_run_command_execution_id');
            }

            if (! Schema::hasColumn('import_apply_plans', 'dry_run_error_message')) {
                $table->text('dry_run_error_message')->nullable()->after('dry_run_output_summary');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted.
    }
};
