<?php

namespace App\Jobs\Directory;

use App\Services\Directory\LdapUserLifecycleService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateLdapUserJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public array $payload,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(LdapUserLifecycleService $service): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        try {
            $result = $service->createUser($this->payload);

            if ($result['ok'] ?? false) {
                SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, [
                    'operation' => 'create_ldap_user',
                    'result' => $result,
                ], [
                    'operation' => 'create_ldap_user',
                    'result' => $result,
                ]);

                return;
            }

            SafeCommandExecutionLogger::markFailed($this->commandExecutionId, $result['message'] ?? 'Create LDAP user failed.', [
                'operation' => 'create_ldap_user',
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed($this->commandExecutionId, $e->getMessage(), [
                'operation' => 'create_ldap_user',
                'payload' => $this->payload,
            ]);

            throw $e;
        }
    }
}
