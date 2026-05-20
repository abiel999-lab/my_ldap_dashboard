<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('operation_jobs', 'operation_action')) {
                $table->string('operation_action')->nullable()->index()->after('operation_type');
            }

            if (! Schema::hasColumn('operation_jobs', 'source')) {
                $table->string('source')->nullable()->index()->after('module');
            }

            if (! Schema::hasColumn('operation_jobs', 'target_type')) {
                $table->string('target_type')->nullable()->index()->after('source');
            }

            if (! Schema::hasColumn('operation_jobs', 'target_key')) {
                $table->string('target_key')->nullable()->index()->after('target_type');
            }

            if (! Schema::hasColumn('operation_jobs', 'target_dn')) {
                $table->text('target_dn')->nullable()->after('target_key');
            }

            if (! Schema::hasColumn('operation_jobs', 'ldap_connection_id')) {
                $table->foreignId('ldap_connection_id')
                    ->nullable()
                    ->after('target_dn')
                    ->constrained('ldap_connections')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('operation_jobs', 'metadata')) {
                $table->json('metadata')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('operation_jobs', 'processed_items')) {
                $table->unsignedInteger('processed_items')->default(0)->after('total_items');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operation_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('operation_jobs', 'metadata')) {
                $table->dropColumn('metadata');
            }

            if (Schema::hasColumn('operation_jobs', 'ldap_connection_id')) {
                $table->dropConstrainedForeignId('ldap_connection_id');
            }

            if (Schema::hasColumn('operation_jobs', 'target_dn')) {
                $table->dropColumn('target_dn');
            }

            if (Schema::hasColumn('operation_jobs', 'target_key')) {
                $table->dropColumn('target_key');
            }

            if (Schema::hasColumn('operation_jobs', 'target_type')) {
                $table->dropColumn('target_type');
            }

            if (Schema::hasColumn('operation_jobs', 'source')) {
                $table->dropColumn('source');
            }

            if (Schema::hasColumn('operation_jobs', 'operation_action')) {
                $table->dropColumn('operation_action');
            }

            if (Schema::hasColumn('operation_jobs', 'processed_items')) {
                $table->dropColumn('processed_items');
            }
        });
    }
};
