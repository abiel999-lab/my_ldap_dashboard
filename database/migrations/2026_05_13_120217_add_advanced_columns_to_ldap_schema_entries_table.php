<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ldap_schema_entries')) {
            return;
        }

        Schema::table('ldap_schema_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('ldap_schema_entries', 'ldap_connection_id')) {
                $table->unsignedBigInteger('ldap_connection_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'schema_type')) {
                $table->string('schema_type')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'primary_name')) {
                $table->string('primary_name')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'display_name')) {
                $table->string('display_name')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'names')) {
                $table->json('names')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'oid')) {
                $table->string('oid')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'kind')) {
                $table->string('kind')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'superior')) {
                $table->string('superior')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'syntax_oid')) {
                $table->string('syntax_oid')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'syntax_description')) {
                $table->string('syntax_description')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'equality_rule')) {
                $table->string('equality_rule')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'ordering_rule')) {
                $table->string('ordering_rule')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'substring_rule')) {
                $table->string('substring_rule')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'is_single_value')) {
                $table->boolean('is_single_value')->default(false)->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'is_operational')) {
                $table->boolean('is_operational')->default(false)->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'is_obsolete')) {
                $table->boolean('is_obsolete')->default(false)->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'must_attributes')) {
                $table->json('must_attributes')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'may_attributes')) {
                $table->json('may_attributes')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'applies_to_attributes')) {
                $table->json('applies_to_attributes')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'raw_definition')) {
                $table->text('raw_definition')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'source_dn')) {
                $table->string('source_dn')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'value_index')) {
                $table->integer('value_index')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'definition_hash')) {
                $table->string('definition_hash')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'status')) {
                $table->string('status')->default('active')->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->index();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable();
            }

            if (! Schema::hasColumn('ldap_schema_entries', 'description')) {
                $table->text('description')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Safe migration: keep columns to avoid data loss.
    }
};
