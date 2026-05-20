<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('universal_ldap_transfer_batches')) {
            return;
        }

        Schema::table('universal_ldap_transfer_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'uuid')) {
                $table->uuid('uuid')->nullable()->index();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'source_base_dn')) {
                $table->text('source_base_dn')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'transfer_scope')) {
                $table->string('transfer_scope')->default('custom_dn');
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'source_rdn_attribute')) {
                $table->string('source_rdn_attribute')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'source_rdn_value')) {
                $table->text('source_rdn_value')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'custom_source_dn')) {
                $table->text('custom_source_dn')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'search_scope')) {
                $table->string('search_scope')->default('sub');
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'filter')) {
                $table->text('filter')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'attributes')) {
                $table->text('attributes')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'target_parent_dn')) {
                $table->text('target_parent_dn')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'target_dn_strategy')) {
                $table->string('target_dn_strategy')->default('preserve_tree');
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'source_base_replacement')) {
                $table->text('source_base_replacement')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'target_base_replacement')) {
                $table->text('target_base_replacement')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'if_target_exists')) {
                $table->string('if_target_exists')->default('skip');
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'excluded_attributes')) {
                $table->text('excluded_attributes')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'include_operational_attributes')) {
                $table->boolean('include_operational_attributes')->default(false);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'preview_only')) {
                $table->boolean('preview_only')->default(true);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'safe_mode')) {
                $table->boolean('safe_mode')->default(true);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'destructive')) {
                $table->boolean('destructive')->default(false);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'size_limit')) {
                $table->integer('size_limit')->default(1000);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'page_size')) {
                $table->integer('page_size')->default(500);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'total_entries')) {
                $table->integer('total_entries')->default(0);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'planned_entries')) {
                $table->integer('planned_entries')->default(0);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'transferred_entries')) {
                $table->integer('transferred_entries')->default(0);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'failed_entries')) {
                $table->integer('failed_entries')->default(0);
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'output_path')) {
                $table->text('output_path')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'output_size_bytes')) {
                $table->bigInteger('output_size_bytes')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'output_hash')) {
                $table->text('output_hash')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'message')) {
                $table->text('message')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'metadata')) {
                $table->json('metadata')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'operation_job_id')) {
                $table->unsignedBigInteger('operation_job_id')->nullable()->index();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }

            if (! Schema::hasColumn('universal_ldap_transfer_batches', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Safe migration: no destructive rollback.
    }
};
