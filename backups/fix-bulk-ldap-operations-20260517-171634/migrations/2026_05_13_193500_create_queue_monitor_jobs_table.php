<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_monitor_jobs')) {
            return;
        }

        Schema::create('queue_monitor_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->string('redis_status')->index();
            $table->string('job_uuid')->nullable()->index();
            $table->string('job_class')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->string('payload_hash')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_monitor_jobs');
    }
};
