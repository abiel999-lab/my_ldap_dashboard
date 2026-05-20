<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ldap_transfer_batches')) {
            return;
        }

        Schema::table('ldap_transfer_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('ldap_transfer_batches', 'source_input_mode')) {
                $table->string('source_input_mode')->default('filter')->index();
            }

            if (! Schema::hasColumn('ldap_transfer_batches', 'source_dns_text')) {
                $table->longText('source_dns_text')->nullable();
            }

            if (! Schema::hasColumn('ldap_transfer_batches', 'source_file_path')) {
                $table->string('source_file_path')->nullable();
            }

            if (! Schema::hasColumn('ldap_transfer_batches', 'target_dn')) {
                $table->string('target_dn')->nullable();
            }

            if (! Schema::hasColumn('ldap_transfer_batches', 'target_dn_mode')) {
                $table->string('target_dn_mode')->default('auto')->index();
            }
        });
    }

    public function down(): void
    {
        // Keep columns for safety.
    }
};
