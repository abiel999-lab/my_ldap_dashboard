<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_transfer_items')) {
            return;
        }

        Schema::create('ldap_transfer_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ldap_transfer_batch_id')->index();

            $table->string('source_dn')->nullable()->index();
            $table->string('target_dn')->nullable()->index();

            $table->string('status')->default('pending')->index(); // pending, success, failed, skipped
            $table->string('operation')->default('copy')->index(); // preview, copy, move, delete_source

            $table->longText('ldif')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_transfer_items');
    }
};
