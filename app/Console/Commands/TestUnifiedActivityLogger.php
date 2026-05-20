<?php

namespace App\Console\Commands;

use App\Services\Observability\UnifiedActivityLogger;
use Illuminate\Console\Command;

class TestUnifiedActivityLogger extends Command
{
    protected $signature = 'iam:observability-log-test';

    protected $description = 'Create test unified operation/audit log records.';

    public function handle(UnifiedActivityLogger $logger): int
    {
        $result = $logger->success(
            module: 'observability.test',
            action: 'test_unified_logger',
            message: 'Unified activity logger test completed successfully.',
            context: [
                'operation_type' => 'observability_test',
                'command_type' => 'observability_test',
                'event' => 'test_unified_logger',
                'target_type' => 'system',
                'target_id' => 'observability',
                'target_dn' => null,
                'total' => 1,
                'success' => 1,
                'failed' => 0,
                'skipped' => 0,
                'source' => 'artisan',
                'write_command_execution' => true,
                'command' => 'php artisan iam:observability-log-test',
                'safe' => true,
                'danger' => false,
            ],
        );

        $this->info('Unified logger test inserted.');
        $this->line('operation_job_id='.($result['operation_job_id'] ?? 'null'));
        $this->line('operation_job_log_id='.($result['operation_job_log_id'] ?? 'null'));
        $this->line('audit_log_id='.($result['audit_log_id'] ?? 'null'));
        $this->line('command_execution_id='.($result['command_execution_id'] ?? 'null'));

        if (! empty($result['errors'])) {
            $this->warn('Logger warnings:');

            foreach ($result['errors'] as $error) {
                $this->warn('- '.$error);
            }
        }

        return self::SUCCESS;
    }
}
