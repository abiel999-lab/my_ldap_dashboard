<?php

namespace App\Console\Commands;

use App\Services\Operations\LdapImportRollbackService;
use Illuminate\Console\Command;

class RollbackImportBatch extends Command
{
    protected $signature = 'iam:import-rollback {batchId}';

    protected $description = 'Rollback LDAP import batch created entries.';

    public function handle(LdapImportRollbackService $service): int
    {
        try {
            $summary = $service->rollback((int) $this->argument('batchId'));

            $this->info('Import rollback completed.');
            $this->line('Batch ID: '.$summary['batch_id']);
            $this->line('Rolled back: '.$summary['rolled_back']);
            $this->line('Skipped: '.$summary['skipped']);
            $this->line('Failed: '.$summary['failed']);

            foreach ($summary['messages'] as $message) {
                $this->line($message);
            }

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
