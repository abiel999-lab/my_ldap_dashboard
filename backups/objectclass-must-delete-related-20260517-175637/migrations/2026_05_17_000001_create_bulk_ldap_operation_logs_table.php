<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bulk_ldap_operations')) {
            Schema::create('bulk_ldap_operations', function (Blueprint $table) {
                $table->id();
                $table->string('operation_name');
                $table->string('ldap_connection_name');
                $table->text('base_dn');
                $table->string('search_scope')->default('subtree');
                $table->text('ldap_filter')->default('(objectClass=*)');
                $table->integer('size_limit')->default(100);
                $table->string('operation_type');
                $table->string('objectclass_name')->nullable();
                $table->string('attribute_name')->nullable();
                $table->text('attribute_value')->nullable();
                $table->text('target_ou_dn')->nullable();
                $table->string('existing_value_behavior')->default('skip');
                $table->string('status')->default('draft');
                $table->json('preview_result')->nullable();
                $table->json('execution_result')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamp('previewed_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index('operation_type');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('bulk_ldap_operation_logs')) {
            Schema::create('bulk_ldap_operation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bulk_ldap_operation_id')->nullable()->constrained('bulk_ldap_operations')->nullOnDelete();
                $table->string('operation_name')->nullable();
                $table->string('operation_type');
                $table->string('ldap_connection_name')->nullable();
                $table->text('base_dn')->nullable();
                $table->text('ldap_filter')->nullable();
                $table->text('target_dn')->nullable();
                $table->string('status')->default('pending');
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->json('result')->nullable();
                $table->string('executed_by')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index('operation_type');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ldap_operation_logs');
        Schema::dropIfExists('bulk_ldap_operations');
    }
};
