<?php

namespace App\Console\Commands;

use App\Services\Operations\LdapImportPlanRepairService;
use Illuminate\Console\Command;

class RepairImportPlans extends Command
{
    protected $signature = 'iam:import-repair-plans {batchId}';

    protected $description = 'Repair LDAP import preview action plans for update/delete rows.';

    public function handle(LdapImportPlanRepairService $service): int
    {
        try {
            $summary = $service->repair((int) $this->argument('batchId'));

            $this->info('Import plans repaired.');
            $this->line('Batch ID: '.$summary['batch_id']);
            $this->line('Row table: '.$summary['row_table']);
            $this->line('Total: '.$summary['total']);
            $this->line('Valid: '.$summary['valid']);
            $this->line('Invalid: '.$summary['invalid']);
            $this->line('Create: '.$summary['create']);
            $this->line('Update: '.$summary['update']);
            $this->line('Delete: '.$summary['delete']);
            $this->line('Repaired: '.$summary['repaired']);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
