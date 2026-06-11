<?php

namespace App\Jobs\Radius;

use App\Services\Radius\WifiReadinessSyncService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class SyncWifiReadinessJob implements ShouldQueue
{
    use Queueable;
    use FoundationQueueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(WifiReadinessSyncService $service): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        try {
            $summary = $service->verifyCurrentMirror();

            $decision = (string) ($summary['decision'] ?? 'UNKNOWN');

            if ($decision === 'READY') {
                SafeCommandExecutionLogger::markSuccess(
                    $this->commandExecutionId,
                    $summary,
                    [
                        'operation' => 'wifi_readiness_sync',
                        'decision' => $decision,
                        'verified' => $summary['verified'] ?? false,
                    ],
                );

                return;
            }

            // Verified warnings are not system failures.
            // Keep CommandExecution status as success, but store the PARTIAL decision in stdout/context.
            SafeCommandExecutionLogger::markSuccess(
                $this->commandExecutionId,
                [
                    'message' => 'WiFi Readiness sync completed with warnings.',
                    'decision' => $decision,
                    'summary' => $summary,
                ],
                [
                    'operation' => 'wifi_readiness_sync',
                    'decision' => $decision,
                    'verified' => $summary['verified'] ?? false,
                    'status_semantics' => 'success_with_warnings',
                ],
            );
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed(
                $this->commandExecutionId,
                $e->getMessage(),
                [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                [
                    'operation' => 'wifi_readiness_sync',
                ],
            );

            throw $e;
        }
    }
}
