<?php

namespace App\Console\Commands;

use App\Services\Operations\LdapImportDependencyGraphService;
use Illuminate\Console\Command;

class BuildImportDependencyPlan extends Command
{
    protected $signature = 'iam:import-dependency-plan {batchId : Import batch ID}';

    protected $description = 'Build LDAP import dependency graph plan for an import batch.';

    public function handle(LdapImportDependencyGraphService $service): int
    {
        $batchId = (int) $this->argument('batchId');

        try {
            $summary = $service->buildForBatch($batchId);

            $this->info('Dependency graph generated.');
            $this->line('Batch ID: '.$summary['batch_id']);
            $this->line('Row table: '.$summary['row_table']);
            $this->line('Rows: '.$summary['total_rows']);
            $this->line('Dependencies total: '.$summary['dependency_total']);
            $this->line('Unique dependencies: '.$summary['unique_dependencies']);
            $this->line('Existing: '.$summary['dependency_existing']);
            $this->line('Missing: '.$summary['dependency_missing']);
            $this->line('Unknown: '.$summary['dependency_unknown']);

            $rows = [];

            foreach ($summary['dependencies'] as $dependency) {
                $rows[] = [
                    $dependency['attribute'] ?? 'N/A',
                    $dependency['dn'] ?? 'N/A',
                    ($dependency['exists'] ?? null) === true
                        ? 'exists'
                        : (($dependency['exists'] ?? null) === false ? 'missing' : 'unknown'),
                    $dependency['plan'] ?? 'N/A',
                    $dependency['reason'] ?? 'N/A',
                ];
            }

            if ($rows !== []) {
                $this->table(
                    ['Attribute', 'Dependency DN', 'Exists', 'Plan', 'Reason'],
                    $rows
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
