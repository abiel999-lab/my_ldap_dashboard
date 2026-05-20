<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ldap_directory_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ldap_connection_id')->constrained('ldap_connections')->cascadeOnDelete();
            $table->string('connection_name');
            $table->text('base_dn');
            $table->text('entry_dn');
            $table->text('parent_dn')->nullable();
            $table->string('entry_rdn')->nullable();
            $table->string('entry_type')->default('entry')->index();
            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('operational_attributes')->nullable();
            $table->unsignedInteger('depth')->default(0)->index();
            $table->string('source_hash')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['ldap_connection_id', 'entry_dn']);
            $table->index(['ldap_connection_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_directory_entries');
    }
};
