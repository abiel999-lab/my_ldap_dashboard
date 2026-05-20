<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('universal_ldap_transfer_batches')) {
            return;
        }

        Schema::create('universal_ldap_transfer_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('status')->default('draft')->index();

            $table->foreignId('source_ldap_connection_id')->index();
            $table->foreignId('target_ldap_connection_id')->index();

            $table->string('transfer_scope')->default('custom_dn')->index();
            $table->text('source_base_dn');
            $table->string('source_rdn_attribute')->nullable();
            $table->string('source_rdn_value')->nullable();
            $table->text('custom_source_dn')->nullable();

            $table->text('target_parent_dn');
            $table->string('target_dn_strategy')->default('preserve_rdn')->index();

            $table->string('search_scope')->default('sub');
            $table->text('filter')->default('(objectClass=*)');
            $table->text('attributes')->nullable();
            $table->unsignedInteger('size_limit')->default(1000);
            $table->unsignedInteger('page_size')->default(500);

            $table->boolean('preview_only')->default(true);
            $table->boolean('safe_mode')->default(true);
            $table->boolean('destructive')->default(false);

            $table->text('output_path')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->string('output_hash')->nullable();

            $table->unsignedBigInteger('operation_job_id')->nullable()->index();
            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('planned_entries')->default(0);
            $table->unsignedInteger('transferred_entries')->default(0);
            $table->unsignedInteger('failed_entries')->default(0);

            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('updated_by')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universal_ldap_transfer_batches');
    }
};
