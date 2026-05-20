<?php

namespace App\Console\Commands\Operations;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsAlignmentCheckCommand extends Command
{
    protected $signature = 'iam:operations-alignment-check';

    protected $description = 'Check whether Operations modules are aligned with generic LDAP admin flow.';

    public function handle(): int
    {
        $this->info('1. Operations Alignment Check');

        $tables = [
            'ldap_transfer_batches',
            'ldap_transfer_items',
            'command_executions',
            'jobs',
            'failed_jobs',
            'import_templates',
            'import_batches',
            'import_apply_plans',
        ];

        foreach ($tables as $table) {
            $exists = Schema::hasTable($table);

            $this->line(($exists ? '✅' : '❌').' table: '.$table);

            if ($exists) {
                $this->line('   rows: '.DB::table($table)->count());
            }
        }

        $this->newLine();
        $this->info('2. LDAP Transfer Center Columns');

        foreach ([
            'source_input_mode',
            'source_dns_text',
            'source_file_path',
            'target_dn',
            'target_dn_mode',
        ] as $column) {
            $ok = Schema::hasColumn('ldap_transfer_batches', $column);
            $this->line(($ok ? '✅' : '❌').' ldap_transfer_batches.'.$column);
        }

        $this->newLine();
        $this->info('3. Recent Transfer Commands');

        if (Schema::hasTable('command_executions')) {
            $commands = DB::table('command_executions')
                ->where('command_type', 'like', 'ldap_transfer_%')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'command_type', 'status', 'exit_code', 'created_at']);

            foreach ($commands as $command) {
                $this->line("#{$command->id} {$command->command_type} {$command->status} exit={$command->exit_code} {$command->created_at}");
            }
        }

        $this->newLine();
        $this->info('4. Target Design');
        $this->line('✅ Transfer: DN-based, Source LDAP + Target LDAP, DN list/text/CSV/filter.');
        $this->line('✅ Import: should be LDAP-connection-aware, DN-first, preview before apply.');
        $this->line('✅ Apply Plans: should be LDIF/dry-run/approval/apply based.');
        $this->line('✅ Logs: Command Executions + Failed Jobs + Operation Logs should show result.');

        return self::SUCCESS;
    }
}
