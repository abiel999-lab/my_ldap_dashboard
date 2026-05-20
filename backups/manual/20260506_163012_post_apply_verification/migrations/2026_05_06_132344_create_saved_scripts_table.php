<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_scripts')) {
            return;
        }

        Schema::create('saved_scripts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name')->index();
            $table->string('script_type')->default('ldapsearch')->index();
            $table->string('status')->default('draft')->index();

            $table->text('description')->nullable();
            $table->longText('script_body');
            $table->json('default_parameters')->nullable();

            $table->boolean('safe_mode_required')->default(true)->index();
            $table->boolean('preview_only')->default(true)->index();
            $table->boolean('destructive')->default(false)->index();

            $table->string('risk_level')->default('low')->index();
            $table->text('risk_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['script_type', 'status']);
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_scripts');
    }
};
