<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_job_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_job_id')->nullable()->constrained('operation_jobs')->cascadeOnDelete();
            $table->foreignId('operation_job_item_id')->nullable()->constrained('operation_job_items')->cascadeOnDelete();
            $table->string('level')->default('info')->index();
            $table->string('event')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->longText('command')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['operation_job_id', 'level']);
            $table->index(['operation_job_item_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_job_logs');
    }
};
