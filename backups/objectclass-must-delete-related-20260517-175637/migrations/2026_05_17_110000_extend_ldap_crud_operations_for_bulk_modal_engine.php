<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ldap_crud_operations')) {
            return;
        }

        Schema::table('ldap_crud_operations', function (Blueprint $table) {
            $t = 'ldap_crud_operations';

            if (! Schema::hasColumn($t, 'target_mode')) {
                $table->string('target_mode')->default('base_dn')->index();
            }

            if (! Schema::hasColumn($t, 'custom_target_dn')) {
                $table->text('custom_target_dn')->nullable();
            }

            if (! Schema::hasColumn($t, 'rdn_attribute')) {
                $table->string('rdn_attribute')->nullable();
            }

            if (! Schema::hasColumn($t, 'rdn_value')) {
                $table->string('rdn_value')->nullable();
            }

            if (! Schema::hasColumn($t, 'missing_objectclass_behavior')) {
                $table->string('missing_objectclass_behavior')->default('skip');
            }

            if (! Schema::hasColumn($t, 'queue_threshold')) {
                $table->integer('queue_threshold')->default(200);
            }

            if (! Schema::hasColumn($t, 'rollback_payload')) {
                $table->json('rollback_payload')->nullable();
            }

            if (! Schema::hasColumn($t, 'rollback_result')) {
                $table->json('rollback_result')->nullable();
            }

            if (! Schema::hasColumn($t, 'rolled_back_at')) {
                $table->timestamp('rolled_back_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        //
    }
};
