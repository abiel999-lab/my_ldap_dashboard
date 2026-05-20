<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ldap_transfer_batches')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = Schema::getColumnListing('ldap_transfer_batches');

        foreach ([
            'ldap_filter',
            'filter',
            'source_base_dn',
            'target_base_dn',
            'target_dn',
            'target_parent_dn',
            'custom_source_dn',
            'source_dns_text',
            'source_rdn_value',
            'source_base_replacement',
            'target_base_replacement',
            'attributes',
            'message',
            'error_message',
            'stdout',
            'stderr',
            'preview_ldif',
            'output_path',
            'output_hash',
        ] as $column) {
            if (in_array($column, $columns, true)) {
                DB::statement('ALTER TABLE ldap_transfer_batches ALTER COLUMN '.$column.' TYPE TEXT');
                DB::statement('ALTER TABLE ldap_transfer_batches ALTER COLUMN '.$column.' DROP NOT NULL');
            }
        }
    }

    public function down(): void
    {
        // Safe migration: no destructive rollback.
    }
};
