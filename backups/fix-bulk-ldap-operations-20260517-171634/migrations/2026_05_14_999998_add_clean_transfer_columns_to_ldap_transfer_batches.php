<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ldap_transfer_batches', 'universal_ldap_transfer_batches'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'uuid')) {
                    $table->uuid('uuid')->nullable()->index();
                }

                if (! Schema::hasColumn($tableName, 'status')) {
                    $table->string('status')->default('draft');
                }

                if (! Schema::hasColumn($tableName, 'source_ldap_connection_id')) {
                    $table->unsignedBigInteger('source_ldap_connection_id')->nullable()->index();
                }

                if (! Schema::hasColumn($tableName, 'target_ldap_connection_id')) {
                    $table->unsignedBigInteger('target_ldap_connection_id')->nullable()->index();
                }

                if (! Schema::hasColumn($tableName, 'transfer_scope')) {
                    $table->string('transfer_scope')->default('custom_dn');
                }

                if (! Schema::hasColumn($tableName, 'source_base_dn')) {
                    $table->text('source_base_dn')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'source_rdn_attribute')) {
                    $table->string('source_rdn_attribute')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'source_rdn_value')) {
                    $table->text('source_rdn_value')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'custom_source_dn')) {
                    $table->text('custom_source_dn')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'target_parent_dn')) {
                    $table->text('target_parent_dn')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'target_dn_strategy')) {
                    $table->string('target_dn_strategy')->default('flatten');
                }

                if (! Schema::hasColumn($tableName, 'source_base_replacement')) {
                    $table->text('source_base_replacement')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'target_base_replacement')) {
                    $table->text('target_base_replacement')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'search_scope')) {
                    $table->string('search_scope')->default('sub');
                }

                if (! Schema::hasColumn($tableName, 'filter')) {
                    $table->text('filter')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'attributes')) {
                    $table->text('attributes')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'size_limit')) {
                    $table->integer('size_limit')->default(1000);
                }

                if (! Schema::hasColumn($tableName, 'page_size')) {
                    $table->integer('page_size')->default(500);
                }

                if (! Schema::hasColumn($tableName, 'if_target_exists')) {
                    $table->string('if_target_exists')->default('skip');
                }

                if (! Schema::hasColumn($tableName, 'excluded_attributes')) {
                    $table->text('excluded_attributes')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'include_operational_attributes')) {
                    $table->boolean('include_operational_attributes')->default(false);
                }

                if (! Schema::hasColumn($tableName, 'preview_only')) {
                    $table->boolean('preview_only')->default(true);
                }

                if (! Schema::hasColumn($tableName, 'safe_mode')) {
                    $table->boolean('safe_mode')->default(true);
                }

                if (! Schema::hasColumn($tableName, 'destructive')) {
                    $table->boolean('destructive')->default(false);
                }

                if (! Schema::hasColumn($tableName, 'output_path')) {
                    $table->text('output_path')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'output_size_bytes')) {
                    $table->bigInteger('output_size_bytes')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'output_hash')) {
                    $table->text('output_hash')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'operation_job_id')) {
                    $table->unsignedBigInteger('operation_job_id')->nullable()->index();
                }

                if (! Schema::hasColumn($tableName, 'total_entries')) {
                    $table->integer('total_entries')->default(0);
                }

                if (! Schema::hasColumn($tableName, 'planned_entries')) {
                    $table->integer('planned_entries')->default(0);
                }

                if (! Schema::hasColumn($tableName, 'transferred_entries')) {
                    $table->integer('transferred_entries')->default(0);
                }

                if (! Schema::hasColumn($tableName, 'failed_entries')) {
                    $table->integer('failed_entries')->default(0);
                }

                if (! Schema::hasColumn($tableName, 'message')) {
                    $table->text('message')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'metadata')) {
                    $table->json('metadata')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'started_at')) {
                    $table->timestamp('started_at')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'finished_at')) {
                    $table->timestamp('finished_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Safe migration: no destructive rollback.
    }
};
