<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_sync_batches')) {
            return;
        }

        Schema::create('ldap_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('ldap_connection_id')->nullable()->index();
            $table->string('status')->default('draft')->index();

            $table->string('sync_scope')->default('full')->index();
            $table->text('base_dn');
            $table->string('target_rdn_attribute')->nullable();
            $table->string('target_rdn_value')->nullable();
            $table->text('custom_target_dn')->nullable();

            $table->string('search_scope')->default('sub');
            $table->text('filter')->default('(objectClass=*)');
            $table->text('attributes')->nullable();

            $table->unsignedInteger('size_limit')->default(5000);
            $table->unsignedInteger('page_size')->default(1000);

            $table->boolean('safe_mode')->default(true);
            $table->boolean('preview_mode')->default(false);
            $table->boolean('destructive')->default(false);

            $table->unsignedBigInteger('operation_job_id')->nullable()->index();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('created_entries')->default(0);
            $table->unsignedInteger('updated_entries')->default(0);
            $table->unsignedInteger('failed_entries')->default(0);

            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('updated_by')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_sync_batches');
    }
};
