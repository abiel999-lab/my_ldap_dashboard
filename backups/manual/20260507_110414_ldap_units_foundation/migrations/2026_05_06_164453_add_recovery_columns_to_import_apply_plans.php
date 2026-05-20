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
            if (! Schema::hasColumn('import_apply_plans', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index()->after('post_apply_error_message');
            }

            if (! Schema::hasColumn('import_apply_plans', 'archived_by')) {
                $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'archive_reason')) {
                $table->text('archive_reason')->nullable()->after('archived_by');
            }

            if (! Schema::hasColumn('import_apply_plans', 'replaced_by_plan_id')) {
                $table->foreignId('replaced_by_plan_id')->nullable()->after('archive_reason')->constrained('import_apply_plans')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'replacement_of_plan_id')) {
                $table->foreignId('replacement_of_plan_id')->nullable()->after('replaced_by_plan_id')->constrained('import_apply_plans')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'recovery_note')) {
                $table->text('recovery_note')->nullable()->after('replacement_of_plan_id');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted.
    }
};
