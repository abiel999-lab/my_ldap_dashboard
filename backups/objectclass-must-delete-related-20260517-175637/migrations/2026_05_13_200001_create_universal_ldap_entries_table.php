<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('universal_ldap_entries')) {
            return;
        }

        Schema::create('universal_ldap_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ldap_connection_id')->index();
            $table->text('dn');
            $table->text('parent_dn')->nullable();
            $table->string('rdn')->nullable();
            $table->string('entry_uuid')->nullable()->index();
            $table->string('entry_type')->default('unknown')->index();
            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->text('raw_ldif')->nullable();
            $table->string('sync_hash')->nullable()->index();
            $table->timestamp('modify_timestamp')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['ldap_connection_id', 'dn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universal_ldap_entries');
    }
};
