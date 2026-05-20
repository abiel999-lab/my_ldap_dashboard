<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_checks')) {
            return;
        }

        Schema::create('health_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('component')->index();
            $table->string('name')->index();
            $table->string('status')->default('unknown')->index();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['component', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
