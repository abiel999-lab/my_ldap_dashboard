<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ldif_export_batches', function (Blueprint $table) {

            if (! Schema::hasColumn('ldif_export_batches', 'export_scope')) {
                $table->string('export_scope')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'target_rdn_attribute')) {
                $table->string('target_rdn_attribute')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'target_rdn_value')) {
                $table->string('target_rdn_value')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'custom_target_dn')) {
                $table->text('custom_target_dn')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'search_scope')) {
                $table->string('search_scope')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'attributes')) {
                $table->text('attributes')->nullable();
            }

            if (! Schema::hasColumn('ldif_export_batches', 'size_limit')) {
                $table->integer('size_limit')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ldif_export_batches', function (Blueprint $table) {

            $columns = [
                'export_scope',
                'target_rdn_attribute',
                'target_rdn_value',
                'custom_target_dn',
                'search_scope',
                'attributes',
                'size_limit',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('ldif_export_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
