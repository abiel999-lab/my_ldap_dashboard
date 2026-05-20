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
            if (! Schema::hasColumn('ldap_crud_operations', 'objectclass_must_values')) {
                $table->json('objectclass_must_values')->nullable();
            }

            if (! Schema::hasColumn('ldap_crud_operations', 'delete_related_objectclass_attributes')) {
                $table->boolean('delete_related_objectclass_attributes')->default(true);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
