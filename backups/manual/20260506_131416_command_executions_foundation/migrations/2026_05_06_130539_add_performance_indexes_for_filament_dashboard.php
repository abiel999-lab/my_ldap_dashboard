<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_jobs')) {
            DB::statement('CREATE INDEX IF NOT EXISTS operation_jobs_status_idx ON operation_jobs (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_jobs_module_idx ON operation_jobs (module)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_jobs_operation_type_idx ON operation_jobs (operation_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_jobs_created_at_idx ON operation_jobs (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_jobs_updated_at_idx ON operation_jobs (updated_at DESC)');
        }

        if (Schema::hasTable('operation_job_items')) {
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_items_operation_job_id_idx ON operation_job_items (operation_job_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_items_status_idx ON operation_job_items (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_items_action_idx ON operation_job_items (action)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_items_created_at_idx ON operation_job_items (created_at DESC)');
        }

        if (Schema::hasTable('operation_job_logs')) {
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_logs_operation_job_id_idx ON operation_job_logs (operation_job_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_logs_operation_job_item_id_idx ON operation_job_logs (operation_job_item_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_logs_level_idx ON operation_job_logs (level)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_logs_event_idx ON operation_job_logs (event)');
            DB::statement('CREATE INDEX IF NOT EXISTS operation_job_logs_created_at_idx ON operation_job_logs (created_at DESC)');
        }

        if (Schema::hasTable('audit_logs')) {
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_created_at_idx ON audit_logs (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_module_idx ON audit_logs (module)');
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_action_idx ON audit_logs (action)');
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_status_idx ON audit_logs (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_actor_email_idx ON audit_logs (actor_email)');
        }

        if (Schema::hasTable('ldap_directory_entries')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_directory_entries_connection_idx ON ldap_directory_entries (ldap_connection_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_directory_entries_type_idx ON ldap_directory_entries (entry_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_directory_entries_depth_idx ON ldap_directory_entries (depth)');
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_directory_entries_last_seen_idx ON ldap_directory_entries (last_seen_at DESC)');
        }

        if (Schema::hasTable('ldap_connections')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_connections_active_default_idx ON ldap_connections (is_active, is_default)');
            DB::statement('CREATE INDEX IF NOT EXISTS ldap_connections_health_idx ON ldap_connections (last_health_status)');
        }

        if (Schema::hasTable('health_checks')) {
            DB::statement('CREATE INDEX IF NOT EXISTS health_checks_component_idx ON health_checks (component)');
            DB::statement('CREATE INDEX IF NOT EXISTS health_checks_status_idx ON health_checks (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS health_checks_checked_at_idx ON health_checks (checked_at DESC)');
        }

        if (Schema::hasTable('jobs')) {
            DB::statement('CREATE INDEX IF NOT EXISTS jobs_queue_idx ON jobs (queue)');
            DB::statement('CREATE INDEX IF NOT EXISTS jobs_available_at_idx ON jobs (available_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS jobs_created_at_idx ON jobs (created_at)');
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::statement('CREATE INDEX IF NOT EXISTS failed_jobs_failed_at_idx ON failed_jobs (failed_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS failed_jobs_queue_idx ON failed_jobs (queue)');
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // These indexes are safe performance helpers.
    }
};
