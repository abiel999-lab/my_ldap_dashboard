<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operation_job_logs')) {
            Schema::create('operation_job_logs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('operation_job_id')->constrained('operation_jobs')->cascadeOnDelete();
                $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->nullOnDelete();
                $table->string('level')->default('info')->index();
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });

            return;
        }

        Schema::table('operation_job_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('operation_job_logs', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('operation_job_logs', 'operation_job_id')) {
                $table->foreignId('operation_job_id')->nullable()->after('uuid')->constrained('operation_jobs')->cascadeOnDelete();
            }

            if (! Schema::hasColumn('operation_job_logs', 'operation_job_item_id')) {
                $table->foreignId('operation_job_item_id')->nullable()->after('operation_job_id')->constrained('operation_job_items')->nullOnDelete();
            }

            if (! Schema::hasColumn('operation_job_logs', 'level')) {
                $table->string('level')->default('info')->index()->after('operation_job_item_id');
            }

            if (! Schema::hasColumn('operation_job_logs', 'message')) {
                $table->text('message')->nullable()->after('level');
            }

            if (! Schema::hasColumn('operation_job_logs', 'context')) {
                $table->json('context')->nullable()->after('message');
            }

            if (! Schema::hasColumn('operation_job_logs', 'created_at')) {
                $table->timestamp('created_at')->useCurrent()->index()->after('context');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Operation logs are audit/progress data and should not be dropped automatically.
    }
};
