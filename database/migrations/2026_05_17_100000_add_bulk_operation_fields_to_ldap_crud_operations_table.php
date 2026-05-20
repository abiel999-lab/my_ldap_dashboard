<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function addColumnIfMissing(Blueprint $table, string $tableName, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition($table);
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('ldap_crud_operations')) {
            return;
        }

        Schema::table('ldap_crud_operations', function (Blueprint $table) {
            $tableName = 'ldap_crud_operations';

            if (! Schema::hasColumn($tableName, 'ldap_connection_id')) {
                $table->unsignedBigInteger('ldap_connection_id')->nullable()->index();
            }

            if (! Schema::hasColumn($tableName, 'operation_kind')) {
                $table->string('operation_kind')->nullable()->index();
            }

            if (! Schema::hasColumn($tableName, 'base_dn')) {
                $table->text('base_dn')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'search_scope')) {
                $table->string('search_scope')->default('subtree');
            }

            if (! Schema::hasColumn($tableName, 'ldap_filter')) {
                $table->text('ldap_filter')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'size_limit')) {
                $table->integer('size_limit')->default(100);
            }

            if (! Schema::hasColumn($tableName, 'objectclass_name')) {
                $table->string('objectclass_name')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'attribute_name')) {
                $table->string('attribute_name')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'attribute_value')) {
                $table->text('attribute_value')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'target_ou_dn')) {
                $table->text('target_ou_dn')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'existing_value_behavior')) {
                $table->string('existing_value_behavior')->default('skip');
            }

            if (! Schema::hasColumn($tableName, 'skip_if_invalid')) {
                $table->boolean('skip_if_invalid')->default(true);
            }

            if (! Schema::hasColumn($tableName, 'require_preview')) {
                $table->boolean('require_preview')->default(true);
            }

            if (! Schema::hasColumn($tableName, 'preview_result')) {
                $table->json('preview_result')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'execution_result')) {
                $table->json('execution_result')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'previewed_at')) {
                $table->timestamp('previewed_at')->nullable();
            }

            if (! Schema::hasColumn($tableName, 'executed_at')) {
                $table->timestamp('executed_at')->nullable();
            }
        });

        if (! Schema::hasTable('ldap_crud_operation_logs')) {
            Schema::create('ldap_crud_operation_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ldap_crud_operation_id')->nullable()->index();
                $table->unsignedBigInteger('ldap_connection_id')->nullable()->index();
                $table->string('operation_kind')->nullable()->index();
                $table->text('target_dn')->nullable();
                $table->string('status')->default('pending')->index();
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->json('result')->nullable();
                $table->string('executed_by')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_crud_operation_logs');
    }
};
