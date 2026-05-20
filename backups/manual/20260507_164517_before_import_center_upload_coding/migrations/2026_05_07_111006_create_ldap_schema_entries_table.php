<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_schema_entries')) {
            return;
        }

        Schema::create('ldap_schema_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ldap_connection_id')->nullable()->constrained('ldap_connections')->nullOnDelete();

            $table->string('schema_type')->index(); // object_class, attribute_type, matching_rule, syntax
            $table->string('oid')->nullable()->index();
            $table->string('name')->nullable()->index();
            $table->string('display_name')->nullable()->index();
            $table->text('description')->nullable();

            $table->string('superior')->nullable()->index();
            $table->string('kind')->nullable()->index(); // structural, auxiliary, abstract, user_attribute, operational_attribute

            $table->boolean('is_single_value')->default(false)->index();
            $table->boolean('is_obsolete')->default(false)->index();
            $table->boolean('is_operational')->default(false)->index();

            $table->string('syntax_oid')->nullable()->index();
            $table->string('equality_rule')->nullable()->index();
            $table->string('ordering_rule')->nullable()->index();
            $table->string('substr_rule')->nullable()->index();

            $table->json('names')->nullable();
            $table->json('must_attributes')->nullable();
            $table->json('may_attributes')->nullable();
            $table->json('extensions')->nullable();
            $table->json('metadata')->nullable();

            $table->longText('raw_definition')->nullable();

            $table->string('source')->default('ldap_subschema')->index();
            $table->string('status')->default('active')->index();

            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['ldap_connection_id', 'schema_type', 'oid']);
            $table->index(['schema_type', 'name']);
            $table->index(['schema_type', 'kind']);
            $table->index(['schema_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_schema_entries');
    }
};
