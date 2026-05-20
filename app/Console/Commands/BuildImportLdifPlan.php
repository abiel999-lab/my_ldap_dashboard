<?php

namespace App\Console\Commands;

use App\Services\Operations\LdapImportLdifPlanService;
use Illuminate\Console\Command;

class BuildImportLdifPlan extends Command
{
    protected $signature = 'iam:import-build-ldif-plan {batchId}';

    protected $description = 'Build internal LDIF plan for LDAP import batch.';

    public function handle(LdapImportLdifPlanService $service): int
    {
        try {
            $summary = $service->buildForBatch((int) $this->argument('batchId'));

            $this->info('LDIF plan generated.');
            $this->line('Batch ID: '.$summary['batch_id']);
            $this->line('Row table: '.$summary['row_table']);
            $this->line('Total rows: '.$summary['total_rows']);
            $this->line('Valid rows: '.$summary['valid_rows']);
            $this->line('Invalid rows: '.$summary['invalid_rows']);
            $this->line('Add rows: '.$summary['add_rows']);
            $this->line('Modify rows: '.$summary['modify_rows']);
            $this->line('Delete rows: '.$summary['delete_rows']);
            $this->line('Schema rows: '.$summary['schema_rows']);
            $this->line('Failed rows: '.$summary['failed_rows']);
            $this->line('Preview LDIF path: '.($summary['preview_ldif_path'] ?? 'N/A'));

            return ($summary['failed_rows'] ?? 0) > 0
                ? self::FAILURE
                : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
