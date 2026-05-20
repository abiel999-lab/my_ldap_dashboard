<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('command_executions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS command_executions_status_idx ON command_executions (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS command_executions_command_type_idx ON command_executions (command_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS command_executions_created_at_idx ON command_executions (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS command_executions_actor_email_idx ON command_executions (actor_email)');
            DB::statement('CREATE INDEX IF NOT EXISTS command_executions_exit_code_idx ON command_executions (exit_code)');
        }

        if (Schema::hasTable('saved_scripts')) {
            DB::statement('CREATE INDEX IF NOT EXISTS saved_scripts_script_type_idx ON saved_scripts (script_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS saved_scripts_status_idx ON saved_scripts (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS saved_scripts_risk_level_idx ON saved_scripts (risk_level)');
            DB::statement('CREATE INDEX IF NOT EXISTS saved_scripts_updated_at_idx ON saved_scripts (updated_at DESC)');
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. These indexes are safe performance helpers.
    }
};
