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
            if (! Schema::hasColumn('import_apply_plans', 'approval_status')) {
                $table->string('approval_status')->default('not_requested')->index()->after('status');
            }

            if (! Schema::hasColumn('import_apply_plans', 'approval_note')) {
                $table->text('approval_note')->nullable()->after('approval_status');
            }

            if (! Schema::hasColumn('import_apply_plans', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approval_note')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->index()->after('approved_by');
            }

            if (! Schema::hasColumn('import_apply_plans', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('import_apply_plans', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->index()->after('rejected_by');
            }

            if (! Schema::hasColumn('import_apply_plans', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }

            if (! Schema::hasColumn('import_apply_plans', 'apply_blocked_reason')) {
                $table->text('apply_blocked_reason')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted for safety.
    }
};
