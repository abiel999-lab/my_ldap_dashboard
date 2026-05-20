<?php

namespace App\Jobs\Directory;

use App\Services\Directory\GenericLdapEntryMutationService;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenericLdapEntryMutationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $modelClass,
        public ?int $recordId,
        public string $operation,
        public array $payload = [],
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(GenericLdapEntryMutationService $service): void
    {
        try {
            if (method_exists(SafeCommandExecutionLogger::class, 'markRunning')) {
                SafeCommandExecutionLogger::markRunning($this->commandExecutionId);
            }

            if ($this->operation === 'create_entry') {
                $result = $service->createEntry(
                    ldapConnectionId: (int) ($this->payload['ldap_connection_id'] ?? 0),
                    dn: (string) ($this->payload['dn'] ?? ''),
                    objectClasses: (array) ($this->payload['object_classes'] ?? []),
                    attributes: (array) ($this->payload['attributes'] ?? []),
                    parentExecutionId: $this->commandExecutionId,
                );
            } else {
                if (! class_exists($this->modelClass)) {
                    throw new \RuntimeException('Model class not found: '.$this->modelClass);
                }

                if (! $this->recordId) {
                    throw new \RuntimeException('Record ID is required.');
                }

                $record = $this->modelClass::query()->findOrFail($this->recordId);

                $result = match ($this->operation) {
                    'add_attribute' => $service->addAttribute(
                        $record,
                        (string) ($this->payload['attribute'] ?? ''),
                        $this->payload['values'] ?? [],
                        $this->commandExecutionId
                    ),

                    'replace_attribute' => $service->replaceAttribute(
                        $record,
                        (string) ($this->payload['attribute'] ?? ''),
                        $this->payload['values'] ?? [],
                        $this->commandExecutionId
                    ),

                    'remove_attribute' => $service->removeAttribute(
                        $record,
                        (string) ($this->payload['attribute'] ?? ''),
                        $this->commandExecutionId
                    ),

                    'add_objectclass' => $service->addObjectClass(
                        $record,
                        (string) ($this->payload['object_class'] ?? ''),
                        (array) ($this->payload['must_attributes'] ?? []),
                        $this->commandExecutionId
                    ),

                    'remove_objectclass' => $service->removeObjectClass(
                        $record,
                        (string) ($this->payload['object_class'] ?? ''),
                        (array) ($this->payload['remove_attributes'] ?? []),
                        $this->commandExecutionId
                    ),

                    'rename_rdn' => $service->renameRdn(
                        $record,
                        (string) ($this->payload['rdn_attribute'] ?? 'cn'),
                        (string) ($this->payload['rdn_value'] ?? ''),
                        (bool) ($this->payload['delete_old_rdn'] ?? true),
                        $this->commandExecutionId
                    ),

                    'move_entry' => $service->moveEntry(
                        $record,
                        (string) ($this->payload['new_parent_dn'] ?? ''),
                        $this->commandExecutionId
                    ),

                    'delete_entry' => $service->deleteEntry(
                        $record,
                        $this->commandExecutionId
                    ),

                    default => throw new \RuntimeException('Unsupported LDAP mutation operation: '.$this->operation),
                };
            }

            if ($result['ok'] ?? false) {
                if (method_exists(SafeCommandExecutionLogger::class, 'markSuccess')) {
                    SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, [
                        'operation' => $this->operation,
                        'model_class' => $this->modelClass,
                        'record_id' => $this->recordId,
                        'payload' => $this->safePayload($this->payload),
                        'result' => $result,
                    ]);
                }

                return;
            }

            if (method_exists(SafeCommandExecutionLogger::class, 'markFailed')) {
                SafeCommandExecutionLogger::markFailed(
                    $this->commandExecutionId,
                    $result['message'] ?? 'LDAP mutation failed.',
                    [
                        'operation' => $this->operation,
                        'model_class' => $this->modelClass,
                        'record_id' => $this->recordId,
                        'payload' => $this->safePayload($this->payload),
                        'result' => $result,
                    ]
                );
            }
        } catch (Throwable $e) {
            if (method_exists(SafeCommandExecutionLogger::class, 'markFailed')) {
                SafeCommandExecutionLogger::markFailed(
                    $this->commandExecutionId,
                    $e->getMessage(),
                    [
                        'operation' => $this->operation,
                        'model_class' => $this->modelClass,
                        'record_id' => $this->recordId,
                        'payload' => $this->safePayload($this->payload),
                    ]
                );
            }

            throw $e;
        }
    }

    private function safePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'bind_password', 'userpassword', 'user_password', 'unicodepwd'], true)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        if (isset($payload['attributes']) && is_array($payload['attributes'])) {
            foreach ($payload['attributes'] as $key => $value) {
                if (in_array(strtolower((string) $key), ['userpassword', 'user_password', 'unicodepwd'], true)) {
                    $payload['attributes'][$key] = '[REDACTED]';
                }
            }
        }

        return $payload;
    }
}
