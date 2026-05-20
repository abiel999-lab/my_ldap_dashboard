<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIndexIfMissing('ldap_user_entries', 'idx_ldap_user_entries_connection_status', 'CREATE INDEX idx_ldap_user_entries_connection_status ON ldap_user_entries (ldap_connection_id, status)');
        $this->createIndexIfMissing('ldap_user_entries', 'idx_ldap_user_entries_connection_uid', 'CREATE INDEX idx_ldap_user_entries_connection_uid ON ldap_user_entries (ldap_connection_id, uid)');
        $this->createIndexIfMissing('ldap_user_entries', 'idx_ldap_user_entries_connection_dn', 'CREATE INDEX idx_ldap_user_entries_connection_dn ON ldap_user_entries (ldap_connection_id, dn)');
        $this->createIndexIfMissing('ldap_user_entries', 'idx_ldap_user_entries_last_seen', 'CREATE INDEX idx_ldap_user_entries_last_seen ON ldap_user_entries (last_seen_at)');
        $this->createIndexIfMissing('ldap_user_entries', 'idx_ldap_user_entries_last_synced', 'CREATE INDEX idx_ldap_user_entries_last_synced ON ldap_user_entries (last_synced_at)');

        $this->createIndexIfMissing('command_executions', 'idx_command_executions_type_status_created', 'CREATE INDEX idx_command_executions_type_status_created ON command_executions (command_type, status, created_at)');
        $this->createIndexIfMissing('command_executions', 'idx_command_executions_status_created', 'CREATE INDEX idx_command_executions_status_created ON command_executions (status, created_at)');
        $this->createIndexIfMissing('command_executions', 'idx_command_executions_created', 'CREATE INDEX idx_command_executions_created ON command_executions (created_at)');

        $this->createIndexIfMissing('operation_jobs', 'idx_operation_jobs_status_created', 'CREATE INDEX idx_operation_jobs_status_created ON operation_jobs (status, created_at)');
        $this->createIndexIfMissing('operation_job_items', 'idx_operation_job_items_job_status', 'CREATE INDEX idx_operation_job_items_job_status ON operation_job_items (operation_job_id, status)');
        $this->createIndexIfMissing('operation_job_logs', 'idx_operation_job_logs_job_created', 'CREATE INDEX idx_operation_job_logs_job_created ON operation_job_logs (operation_job_id, created_at)');

        $this->createIndexIfMissing('import_batches', 'idx_import_batches_type_status_created', 'CREATE INDEX idx_import_batches_type_status_created ON import_batches (import_type, status, created_at)');
        $this->createIndexIfMissing('import_apply_plans', 'idx_import_apply_plans_status_created', 'CREATE INDEX idx_import_apply_plans_status_created ON import_apply_plans (status, created_at)');
    }

    public function down(): void
    {
        foreach ([
            'idx_ldap_user_entries_connection_status',
            'idx_ldap_user_entries_connection_uid',
            'idx_ldap_user_entries_connection_dn',
            'idx_ldap_user_entries_last_seen',
            'idx_ldap_user_entries_last_synced',
            'idx_command_executions_type_status_created',
            'idx_command_executions_status_created',
            'idx_command_executions_created',
            'idx_operation_jobs_status_created',
            'idx_operation_job_items_job_status',
            'idx_operation_job_logs_job_created',
            'idx_import_batches_type_status_created',
            'idx_import_apply_plans_status_created',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS '.$index);
        }
    }

    private function createIndexIfMissing(string $table, string $indexName, string $sql): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = DB::selectOne("
            SELECT 1
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = ?
              AND indexname = ?
        ", [$table, $indexName]);

        if (! $exists) {
            DB::statement($sql);
        }
    }
};
