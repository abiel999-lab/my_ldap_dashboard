<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_ldap_operation_logs', function (Blueprint $table) {
            $table->id();
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

    public function down(): void
    {
        Schema::dropIfExists('bulk_ldap_operation_logs');
    }
};
