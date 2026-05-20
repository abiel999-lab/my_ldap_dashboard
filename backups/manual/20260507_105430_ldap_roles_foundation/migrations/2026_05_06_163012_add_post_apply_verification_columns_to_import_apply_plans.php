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
            if (! Schema::hasColumn('import_apply_plans', 'post_apply_verified_at')) {
                $table->timestamp('post_apply_verified_at')->nullable()->index()->after('real_apply_confirmation');
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_verified_by')) {
                $table->foreignId('post_apply_verified_by')->nullable()->after('post_apply_verified_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_command_execution_id')) {
                $table->foreignId('post_apply_command_execution_id')->nullable()->after('post_apply_verified_by')->constrained('command_executions')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_verified_count')) {
                $table->unsignedInteger('post_apply_verified_count')->default(0)->after('post_apply_command_execution_id');
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_missing_count')) {
                $table->unsignedInteger('post_apply_missing_count')->default(0)->after('post_apply_verified_count');
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_output_summary')) {
                $table->text('post_apply_output_summary')->nullable()->after('post_apply_missing_count');
            }

            if (! Schema::hasColumn('import_apply_plans', 'post_apply_error_message')) {
                $table->text('post_apply_error_message')->nullable()->after('post_apply_output_summary');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted.
    }
};
