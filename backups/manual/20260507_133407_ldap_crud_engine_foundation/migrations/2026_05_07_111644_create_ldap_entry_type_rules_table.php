<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ldap_entry_type_rules')) {
            return;
        }

        Schema::create('ldap_entry_type_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('rule_key')->unique();
            $table->string('name')->index();
            $table->string('entry_type')->index();
            $table->string('entry_category')->nullable()->index();

            $table->json('required_object_classes')->nullable();
            $table->json('optional_object_classes')->nullable();
            $table->json('dn_contains_patterns')->nullable();
            $table->json('dn_starts_with_patterns')->nullable();
            $table->json('rdn_attributes')->nullable();

            $table->string('identifier_attribute')->nullable();
            $table->string('display_attribute')->nullable();
            $table->string('email_attribute')->nullable();
            $table->string('uuid_attribute')->nullable();
            $table->string('membership_attribute')->nullable();

            $table->string('filament_icon')->nullable();
            $table->string('badge_color')->nullable();

            $table->unsignedInteger('priority')->default(100)->index();

            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_system')->default(false)->index();

            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['entry_type', 'is_enabled']);
            $table->index(['entry_category', 'is_enabled']);
            $table->index(['priority', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_entry_type_rules');
    }
};
