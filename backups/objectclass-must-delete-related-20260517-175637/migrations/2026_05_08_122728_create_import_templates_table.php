<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_templates')) {
            return;
        }

        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ldap_connection_id')->nullable()->index();

            $table->string('name');
            $table->string('template_purpose')->default('create')->index();
            $table->string('entry_type')->default('user')->index();
            $table->string('file_format')->default('csv')->index();

            $table->string('base_dn')->default('dc=petra,dc=ac,dc=id');
            $table->string('target_ou')->nullable()->default('people');
            $table->string('rdn_attribute')->default('uid');

            $table->json('object_classes')->nullable();
            $table->json('attributes')->nullable();
            $table->json('sample_values')->nullable();

            $table->string('multi_value_separator')->default(';');
            $table->unsignedInteger('sample_rows')->default(3);

            $table->string('output_disk')->nullable()->default('local');
            $table->string('output_path')->nullable();
            $table->string('output_filename')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->string('output_hash')->nullable();

            $table->string('status')->default('draft')->index();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};
