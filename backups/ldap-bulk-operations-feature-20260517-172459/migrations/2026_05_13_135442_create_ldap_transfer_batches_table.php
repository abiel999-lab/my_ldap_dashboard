<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_transfer_batches')) {
            return;
        }

        Schema::create('ldap_transfer_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('source_ldap_connection_id')->nullable()->index();
            $table->foreignId('target_ldap_connection_id')->nullable()->index();

            $table->string('name')->nullable();
            $table->string('mode')->default('copy')->index(); // preview, copy, move
            $table->string('status')->default('draft')->index(); // draft, queued, running, success, failed

            $table->string('source_base_dn')->nullable();
            $table->string('target_base_dn')->nullable();
            $table->string('ldap_filter')->default('(objectClass=*)');
            $table->string('scope')->default('sub'); // base, one, sub

            $table->boolean('include_operational_attributes')->default(false);
            $table->boolean('delete_source_after_copy')->default(false);
            $table->string('collision_strategy')->default('skip'); // skip, replace, fail

            $table->json('excluded_attributes')->nullable();
            $table->json('options')->nullable();

            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('success_entries')->default(0);
            $table->unsignedInteger('failed_entries')->default(0);
            $table->unsignedInteger('skipped_entries')->default(0);

            $table->foreignId('command_execution_id')->nullable()->index();

            $table->longText('preview_ldif')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_transfer_batches');
    }
};
