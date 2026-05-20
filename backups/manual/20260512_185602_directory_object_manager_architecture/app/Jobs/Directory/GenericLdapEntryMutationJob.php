<?php

namespace App\Jobs\Directory;

use App\Services\Directory\GenericLdapEntryMutationService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenericLdapEntryMutationJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $modelClass,
        public int $recordId,
        public string $operation,
        public array $payload = [],
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(GenericLdapEntryMutationService $service): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        try {
            if (! class_exists($this->modelClass)) {
                throw new \RuntimeException('Model class not found: '.$this->modelClass);
            }

            $record = $this->modelClass::query()->findOrFail($this->recordId);

            $result = match ($this->operation) {
                'add_attribute' => $service->addAttribute(
                    $record,
                    (string) $this->payload['attribute'],
                    $this->payload['values'] ?? [],
                    $this->commandExecutionId
                ),

                'replace_attribute' => $service->replaceAttribute(
                    $record,
                    (string) $this->payload['attribute'],
                    $this->payload['values'] ?? [],
                    $this->commandExecutionId
                ),

                'remove_attribute' => $service->removeAttribute(
                    $record,
                    (string) $this->payload['attribute'],
                    $this->commandExecutionId
                ),

                'add_objectclass' => $service->addObjectClass(
                    $record,
                    (string) $this->payload['object_class'],
                    (array) ($this->payload['must_attributes'] ?? []),
                    $this->commandExecutionId
                ),

                'remove_objectclass' => $service->removeObjectClass(
                    $record,
                    (string) $this->payload['object_class'],
                    (array) ($this->payload['remove_attributes'] ?? []),
                    $this->commandExecutionId
                ),

                'rename_rdn' => $service->renameRdn(
                    $record,
                    (string) $this->payload['rdn_attribute'],
                    (string) $this->payload['rdn_value'],
                    (bool) ($this->payload['delete_old_rdn'] ?? true),
                    $this->commandExecutionId
                ),

                'move_ou' => $service->moveOu(
                    $record,
                    (string) $this->payload['new_parent_dn'],
                    $this->commandExecutionId
                ),

                'delete_entry' => $service->deleteEntry(
                    $record,
                    $this->commandExecutionId
                ),

                default => throw new \RuntimeException('Unsupported LDAP mutation operation: '.$this->operation),
            };

            if ($result['ok'] ?? false) {
                SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, [
                    'operation' => $this->operation,
                    'model_class' => $this->modelClass,
                    'record_id' => $this->recordId,
                    'payload' => $this->payload,
                    'result' => $result,
                ]);

                return;
            }

            SafeCommandExecutionLogger::markFailed(
                $this->commandExecutionId,
                $result['message'] ?? 'LDAP mutation failed.',
                [
                    'operation' => $this->operation,
                    'model_class' => $this->modelClass,
                    'record_id' => $this->recordId,
                    'payload' => $this->payload,
                    'result' => $result,
                ]
            );
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed(
                $this->commandExecutionId,
                $e->getMessage(),
                [
                    'operation' => $this->operation,
                    'model_class' => $this->modelClass,
                    'record_id' => $this->recordId,
                    'payload' => $this->payload,
                ]
            );

            throw $e;
        }
    }
}
