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

class BulkGenericLdapEntryMutationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $modelClass,
        public array $recordIds,
        public string $operation,
        public array $payload = [],
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(GenericLdapEntryMutationService $service): void
    {
        if (method_exists(SafeCommandExecutionLogger::class, 'markRunning')) {
            SafeCommandExecutionLogger::markRunning($this->commandExecutionId);
        }

        $success = 0;
        $failed = 0;
        $results = [];

        try {
            if (! class_exists($this->modelClass)) {
                throw new \RuntimeException('Model class not found: '.$this->modelClass);
            }

            $this->modelClass::query()
                ->whereIn('id', $this->recordIds)
                ->orderBy('id')
                ->chunkById(25, function ($records) use ($service, &$success, &$failed, &$results): void {
                    foreach ($records as $record) {
                        try {
                            $result = match ($this->operation) {
                                'delete_entry' => $service->deleteEntry(
                                    $record,
                                    $this->commandExecutionId
                                ),

                                'move_entry' => $service->moveEntry(
                                    $record,
                                    (string) ($this->payload['new_parent_dn'] ?? ''),
                                    $this->commandExecutionId
                                ),

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

                                default => throw new \RuntimeException('Unsupported bulk LDAP mutation operation: '.$this->operation),
                            };

                            if ($result['ok'] ?? false) {
                                $success++;
                            } else {
                                $failed++;
                            }

                            $results[] = [
                                'record_id' => $record->id ?? null,
                                'dn' => $record->dn ?? null,
                                'ok' => (bool) ($result['ok'] ?? false),
                                'message' => $result['message'] ?? null,
                                'child_command_execution_id' => $result['command_execution_id'] ?? null,
                            ];
                        } catch (Throwable $e) {
                            $failed++;

                            $results[] = [
                                'record_id' => $record->id ?? null,
                                'dn' => $record->dn ?? null,
                                'ok' => false,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                });

            $summary = [
                'operation' => 'bulk_'.$this->operation,
                'model_class' => $this->modelClass,
                'record_count' => count($this->recordIds),
                'success' => $success,
                'failed' => $failed,
                'payload' => $this->payload,
                'results' => $results,
            ];

            if ($failed > 0) {
                if (method_exists(SafeCommandExecutionLogger::class, 'markFailed')) {
                    SafeCommandExecutionLogger::markFailed(
                        $this->commandExecutionId,
                        'Bulk LDAP operation completed with some failed items.',
                        $summary
                    );
                }

                return;
            }

            if (method_exists(SafeCommandExecutionLogger::class, 'markSuccess')) {
                SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $summary, $summary);
            }
        } catch (Throwable $e) {
            if (method_exists(SafeCommandExecutionLogger::class, 'markFailed')) {
                SafeCommandExecutionLogger::markFailed(
                    $this->commandExecutionId,
                    $e->getMessage(),
                    [
                        'operation' => 'bulk_'.$this->operation,
                        'model_class' => $this->modelClass,
                        'record_ids' => $this->recordIds,
                        'payload' => $this->payload,
                    ]
                );
            }

            throw $e;
        }
    }
}
