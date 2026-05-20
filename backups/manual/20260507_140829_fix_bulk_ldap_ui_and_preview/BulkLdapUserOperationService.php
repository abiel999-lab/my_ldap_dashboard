<?php

namespace App\Services\Operations;

use App\Jobs\Operations\ExecuteBulkLdapOperationJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\BulkLdapOperation;
use App\Models\Operations\BulkLdapOperationItem;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class BulkLdapUserOperationService
{
    public function previewCreateTestUsers(BulkLdapOperation $operation): array
    {
        $operation->loadMissing('ldapConnection');

        if (! $operation->ldapConnection) {
            $operation->forceFill([
                'status' => 'preview_failed',
                'message' => 'LDAP connection is missing.',
                'error_message' => 'LDAP connection is missing.',
                'failed_at' => now(),
            ])->save();

            return [
                'ok' => false,
                'message' => 'LDAP connection is missing.',
            ];
        }

        $targetOu = $this->normalizeDn((string) ($operation->target_ou_dn ?: 'ou=people,'.$operation->ldapConnection->base_dn));
        $count = max(1, min((int) $operation->user_count, 10000));
        $startNumber = max(1, (int) $operation->start_number);
        $padding = max(3, min((int) $operation->number_padding, 8));

        DB::transaction(function () use ($operation, $targetOu, $count, $startNumber, $padding): void {
            $operation->items()->delete();

            $objectClasses = $operation->default_object_classes ?: ['top', 'person', 'organizationalPerson', 'inetOrgPerson'];
            $defaultAttributes = $operation->default_attributes ?: [];

            for ($i = 0; $i < $count; $i++) {
                $number = $startNumber + $i;
                $padded = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
                $uid = $operation->uid_prefix.$padded;
                $cn = trim($operation->display_name_prefix.' '.$padded);
                $sn = 'User '.$padded;
                $mail = $uid.'@'.$operation->email_domain;
                $targetDn = 'uid='.$uid.','.$targetOu;

                $attributes = array_merge([
                    'uid' => $uid,
                    'cn' => $cn,
                    'sn' => $sn,
                    'mail' => $mail,
                ], $defaultAttributes);

                $ldif = $this->buildCreateLdif($targetDn, $objectClasses, $attributes);
                $payloadHash = hash('sha256', json_encode([
                    'action' => 'create_user',
                    'target_dn' => $targetDn,
                    'object_classes' => $objectClasses,
                    'attributes' => $attributes,
                ], JSON_UNESCAPED_SLASHES));

                BulkLdapOperationItem::query()->create([
                    'bulk_ldap_operation_id' => $operation->id,
                    'ldap_connection_id' => $operation->ldap_connection_id,
                    'sequence' => $i + 1,
                    'action' => 'create_user',
                    'status' => 'pending',
                    'uid' => $uid,
                    'target_dn' => $targetDn,
                    'object_classes' => $objectClasses,
                    'attributes' => $attributes,
                    'payload_hash' => $payloadHash,
                    'ldif_preview' => $ldif,
                    'metadata' => [
                        'target_ou_dn' => $targetOu,
                        'generated' => true,
                        'idempotent' => true,
                    ],
                ]);
            }

            $operation->forceFill([
                'operation_type' => 'bulk_create_test_users',
                'status' => 'previewed',
                'target_ou_dn' => $targetOu,
                'safe_mode' => true,
                'dry_run' => false,
                'destructive' => false,
                'approval_required' => true,
                'total_items' => $count,
                'pending_items' => $count,
                'running_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'skipped_items' => 0,
                'already_applied_items' => 0,
                'conflict_items' => 0,
                'processed_items' => 0,
                'previewed_at' => now(),
                'message' => 'Bulk LDAP create test users preview generated.',
                'error_message' => null,
            ])->save();
        });

        $this->audit([
            'module' => 'operations.bulk_ldap',
            'action' => 'preview_bulk_create_test_users',
            'status' => 'success',
            'target_type' => BulkLdapOperation::class,
            'target_key' => (string) $operation->id,
            'target_dn' => $targetOu,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'request_payload' => [
                'user_count' => $count,
                'uid_prefix' => $operation->uid_prefix,
                'target_ou_dn' => $targetOu,
                'ldap_was_changed' => false,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Preview generated: '.$count.' users.',
            'total_items' => $count,
        ];
    }

    public function queueApply(BulkLdapOperation $operation, bool $retryOnlyFailed = false): array
    {
        $operation->refresh();

        if (! in_array($operation->status, ['previewed', 'partial_success', 'failed', 'queued', 'running'], true)) {
            return [
                'ok' => false,
                'message' => 'Operation must be previewed before queue apply.',
            ];
        }

        if ($operation->items()->count() <= 0) {
            return [
                'ok' => false,
                'message' => 'No operation items found. Generate preview first.',
            ];
        }

        $operationJobId = $this->createOperationJob($operation, $retryOnlyFailed);

        $operation->forceFill([
            'status' => 'queued',
            'operation_job_id' => $operationJobId,
            'queued_at' => now(),
            'message' => $retryOnlyFailed ? 'Bulk LDAP retry failed items queued.' : 'Bulk LDAP operation queued.',
            'error_message' => null,
        ])->save();

        ExecuteBulkLdapOperationJob::dispatch($operation->id, $retryOnlyFailed);

        $this->audit([
            'module' => 'operations.bulk_ldap',
            'action' => $retryOnlyFailed ? 'queue_retry_bulk_ldap_operation' : 'queue_bulk_ldap_operation',
            'status' => 'success',
            'target_type' => BulkLdapOperation::class,
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->target_ou_dn,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'operation_job_id' => $operationJobId,
            'request_payload' => [
                'retry_only_failed' => $retryOnlyFailed,
                'ldap_was_changed' => false,
            ],
        ]);

        return [
            'ok' => true,
            'message' => $operation->message,
            'operation_job_id' => $operationJobId,
        ];
    }

    public function executeQueuedOperation(BulkLdapOperation $operation, bool $retryOnlyFailed = false): void
    {
        $operation->refresh();
        $operation->loadMissing('ldapConnection');

        $operation->forceFill([
            'status' => 'running',
            'started_at' => $operation->started_at ?: now(),
            'message' => 'Bulk LDAP operation is running.',
        ])->save();

        $this->updateOperationJob($operation, [
            'status' => 'running',
            'started_at' => now(),
        ]);

        $query = $operation->items()
            ->orderBy('sequence');

        if ($retryOnlyFailed) {
            $query->whereIn('status', ['failed', 'pending', 'running']);
        } else {
            $query->whereIn('status', ['pending', 'failed']);
        }

        $query->chunkById(50, function ($items) use ($operation): void {
            foreach ($items as $item) {
                $this->executeItem($operation->fresh(), $item);
                $this->recalculateCounters($operation->fresh());
            }
        });

        $operation->refresh();
        $this->recalculateCounters($operation);

        $status = $operation->failed_items > 0
            ? ($operation->success_items > 0 || $operation->already_applied_items > 0 ? 'partial_success' : 'failed')
            : 'success';

        $operation->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'message' => $status === 'success'
                ? 'Bulk LDAP operation completed successfully.'
                : 'Bulk LDAP operation completed with failed items.',
            'error_message' => $status === 'success' ? null : 'Some items failed. Check Bulk LDAP Operation Items.',
        ])->save();

        $this->updateOperationJob($operation, [
            'status' => $status,
            'finished_at' => now(),
            'processed_items' => $operation->processed_items,
            'success_items' => $operation->success_items + $operation->already_applied_items,
            'failed_items' => $operation->failed_items,
        ]);

        $this->audit([
            'module' => 'operations.bulk_ldap',
            'action' => 'execute_bulk_ldap_operation',
            'status' => $status === 'success' ? 'success' : 'failed',
            'target_type' => BulkLdapOperation::class,
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->target_ou_dn,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'operation_job_id' => $operation->operation_job_id,
            'request_payload' => [
                'status' => $status,
                'total_items' => $operation->total_items,
                'success_items' => $operation->success_items,
                'already_applied_items' => $operation->already_applied_items,
                'failed_items' => $operation->failed_items,
                'ldap_was_changed' => $operation->success_items > 0,
            ],
            'error_message' => $operation->error_message,
        ]);

        $this->syncDirectoryExplorerQuietly($operation);
    }

    private function executeItem(BulkLdapOperation $operation, BulkLdapOperationItem $item): void
    {
        $startedAt = microtime(true);
        $item->refresh();

        if (in_array($item->status, ['success', 'already_applied', 'skipped'], true)) {
            return;
        }

        $item->forceFill([
            'status' => 'running',
            'attempt_count' => (int) $item->attempt_count + 1,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $this->updateOperationJobItem($operation, $item, [
            'status' => 'running',
            'started_at' => now(),
        ]);

        if (! $operation->ldapConnection) {
            $this->markItemFailed($operation, $item, 'LDAP connection is missing.', null, null, 1);
            return;
        }

        if ($this->dnExists($operation->ldapConnection, $item->target_dn)) {
            $item->forceFill([
                'status' => 'already_applied',
                'stdout' => 'DN already exists. Item marked already_applied and skipped safely.',
                'stderr' => null,
                'exit_code' => 0,
                'finished_at' => now(),
            ])->save();

            $this->updateOperationJobItem($operation, $item, [
                'status' => 'already_applied',
                'finished_at' => now(),
                'output_payload' => [
                    'message' => 'DN already exists. Item marked already_applied.',
                    'target_dn' => $item->target_dn,
                ],
            ]);

            $this->auditItem($operation, $item, 'already_applied', 'DN already exists. Item skipped safely.', null);

            return;
        }

        $ldifPath = $this->writeItemLdif($operation, $item);
        $command = $this->buildLdapModifyCommand($operation->ldapConnection, $ldifPath);
        $displayCommand = $this->displayLdapModifyCommand($operation->ldapConnection, $ldifPath);

        $execution = $this->createCommandExecution($operation, $item, $displayCommand);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());
            $exitCode = $process->getExitCode();
            $ok = $process->isSuccessful();

            $execution->forceFill([
                'status' => $ok ? 'success' : 'failed',
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
                'error_message' => $ok ? null : 'Bulk LDAP item command failed.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            if ($ok) {
                $item->forceFill([
                    'status' => 'success',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $exitCode,
                    'command_execution_id' => $execution->id,
                    'finished_at' => now(),
                    'after_value' => [
                        'target_dn' => $item->target_dn,
                        'uid' => $item->uid,
                        'created' => true,
                    ],
                ])->save();

                $this->updateOperationJobItem($operation, $item, [
                    'status' => 'success',
                    'finished_at' => now(),
                    'output_payload' => [
                        'target_dn' => $item->target_dn,
                        'uid' => $item->uid,
                        'stdout' => $stdout,
                    ],
                ]);

                $this->auditItem($operation, $item, 'success', 'Bulk LDAP item created successfully.', $execution);
            } else {
                $this->markItemFailed($operation, $item, $stderr ?: 'Bulk LDAP item command failed.', $execution, $stdout, $exitCode);
            }
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->markItemFailed($operation, $item, $exception->getMessage(), $execution, null, 1);
        }
    }

    private function markItemFailed(
        BulkLdapOperation $operation,
        BulkLdapOperationItem $item,
        string $error,
        ?CommandExecution $execution,
        ?string $stdout,
        ?int $exitCode,
    ): void {
        $item->forceFill([
            'status' => 'failed',
            'stdout' => $stdout,
            'stderr' => $error,
            'exit_code' => $exitCode,
            'command_execution_id' => $execution?->id,
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();

        $this->updateOperationJobItem($operation, $item, [
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $error,
            'output_payload' => [
                'target_dn' => $item->target_dn,
                'uid' => $item->uid,
                'error' => $error,
            ],
        ]);

        $this->auditItem($operation, $item, 'failed', $error, $execution);
    }

    private function buildCreateLdif(string $dn, array $objectClasses, array $attributes): string
    {
        $lines = [
            'dn: '.$dn,
            'changetype: add',
        ];

        foreach ($objectClasses as $objectClass) {
            $objectClass = trim((string) $objectClass);

            if ($objectClass !== '') {
                $lines[] = 'objectClass: '.$objectClass;
            }
        }

        foreach ($attributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    $singleValue = trim((string) $singleValue);

                    if ($singleValue !== '') {
                        $lines[] = $attribute.': '.$singleValue;
                    }
                }

                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $lines[] = $attribute.': '.$value;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function dnExists(LdapConnection $connection, string $dn): bool
    {
        $command = [
            'ldapsearch',
            '-LLL',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-b',
            $dn,
            '-s',
            'base',
            '(objectClass=*)',
            'dn',
        ];

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(30);
            $process->run();

            return str_contains($process->getOutput(), 'dn: ');
        } catch (Throwable) {
            return false;
        }
    }

    private function writeItemLdif(BulkLdapOperation $operation, BulkLdapOperationItem $item): string
    {
        $path = 'operations/bulk-ldap/'.$operation->id.'/item_'.$item->sequence.'_'.$item->uid.'.ldif';

        Storage::disk('local')->put($path, $item->ldif_preview);

        return storage_path('app/private/'.$path);
    }

    private function buildLdapModifyCommand(LdapConnection $connection, string $ldifPath): array
    {
        return [
            'ldapmodify',
            '-v',
            '-x',
            '-H',
            'ldap://'.$connection->host.':'.$connection->port,
            '-D',
            $connection->bind_dn,
            '-w',
            $connection->bind_password,
            '-f',
            $ldifPath,
        ];
    }

    private function displayLdapModifyCommand(LdapConnection $connection, string $ldifPath): string
    {
        return 'ldapmodify -v -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -f '.$ldifPath;
    }

    private function createCommandExecution(BulkLdapOperation $operation, BulkLdapOperationItem $item, string $displayCommand): CommandExecution
    {
        $actor = Auth::user();

        return CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'operations.bulk_ldap',
            'command_type' => 'bulk_ldap_create_user',
            'status' => 'running',
            'command' => $displayCommand,
            'working_directory' => base_path(),
            'environment_context' => [
                'bulk_ldap_operation_id' => $operation->id,
                'bulk_ldap_operation_item_id' => $item->id,
                'ldap_connection_id' => $operation->ldap_connection_id,
                'target_dn' => $item->target_dn,
                'uid' => $item->uid,
            ],
            'safe_mode' => true,
            'preview_mode' => false,
            'destructive' => false,
            'started_at' => now(),
        ]);
    }

    private function createOperationJob(BulkLdapOperation $operation, bool $retryOnlyFailed): ?int
    {
        if (! Schema::hasTable('operation_jobs')) {
            return null;
        }

        $payload = [
            'name' => ($retryOnlyFailed ? 'Retry ' : '').'Bulk LDAP Create Users - '.$operation->name,
            'operation_type' => 'bulk_ldap_create_users',
            'module' => 'operations.bulk_ldap',
            'action' => $retryOnlyFailed ? 'retry_failed_bulk_create_users' : 'bulk_create_users',
            'status' => 'queued',
            'source' => 'filament',
            'target_type' => BulkLdapOperation::class,
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->target_ou_dn,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'total_items' => $retryOnlyFailed
                ? $operation->items()->whereIn('status', ['failed', 'pending', 'running'])->count()
                : $operation->items()->whereIn('status', ['pending', 'failed'])->count(),
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'metadata' => json_encode([
                'bulk_ldap_operation_id' => $operation->id,
                'retry_only_failed' => $retryOnlyFailed,
                'uid_prefix' => $operation->uid_prefix,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $payload = $this->filterTablePayload('operation_jobs', $payload);

        return DB::table('operation_jobs')->insertGetId($payload);
    }

    private function updateOperationJob(BulkLdapOperation $operation, array $payload): void
    {
        if (! $operation->operation_job_id || ! Schema::hasTable('operation_jobs')) {
            return;
        }

        $payload['updated_at'] = now();

        DB::table('operation_jobs')
            ->where('id', $operation->operation_job_id)
            ->update($this->filterTablePayload('operation_jobs', $payload));
    }

    private function updateOperationJobItem(BulkLdapOperation $operation, BulkLdapOperationItem $item, array $payload): void
    {
        if (! Schema::hasTable('operation_job_items')) {
            return;
        }

        if (! $item->operation_job_item_id) {
            $insert = [
                'operation_job_id' => $operation->operation_job_id,
                'name' => 'Bulk LDAP item #'.$item->sequence.' '.$item->uid,
                'action' => $item->action,
                'status' => $payload['status'] ?? $item->status,
                'target_type' => BulkLdapOperationItem::class,
                'target_key' => (string) $item->id,
                'target_dn' => $item->target_dn,
                'input_payload' => json_encode([
                    'uid' => $item->uid,
                    'target_dn' => $item->target_dn,
                    'payload_hash' => $item->payload_hash,
                ], JSON_UNESCAPED_SLASHES),
                'output_payload' => isset($payload['output_payload']) ? json_encode($payload['output_payload'], JSON_UNESCAPED_SLASHES) : null,
                'error_message' => $payload['error_message'] ?? null,
                'started_at' => $payload['started_at'] ?? null,
                'finished_at' => $payload['finished_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $id = DB::table('operation_job_items')->insertGetId($this->filterTablePayload('operation_job_items', $insert));

            $item->forceFill([
                'operation_job_item_id' => $id,
            ])->save();

            return;
        }

        $update = [
            'status' => $payload['status'] ?? $item->status,
            'error_message' => $payload['error_message'] ?? null,
            'output_payload' => isset($payload['output_payload']) ? json_encode($payload['output_payload'], JSON_UNESCAPED_SLASHES) : null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'updated_at' => now(),
        ];

        DB::table('operation_job_items')
            ->where('id', $item->operation_job_item_id)
            ->update($this->filterTablePayload('operation_job_items', $update));
    }

    private function recalculateCounters(BulkLdapOperation $operation): void
    {
        $counts = $operation->items()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $pending = (int) ($counts['pending'] ?? 0);
        $running = (int) ($counts['running'] ?? 0);
        $success = (int) ($counts['success'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $already = (int) ($counts['already_applied'] ?? 0);
        $conflict = (int) ($counts['conflict'] ?? 0);
        $processed = $success + $failed + $skipped + $already + $conflict;

        $operation->forceFill([
            'total_items' => $operation->items()->count(),
            'pending_items' => $pending,
            'running_items' => $running,
            'success_items' => $success,
            'failed_items' => $failed,
            'skipped_items' => $skipped,
            'already_applied_items' => $already,
            'conflict_items' => $conflict,
            'processed_items' => $processed,
        ])->save();

        $this->updateOperationJob($operation->fresh(), [
            'processed_items' => $processed,
            'success_items' => $success + $already,
            'failed_items' => $failed,
        ]);
    }

    private function auditItem(BulkLdapOperation $operation, BulkLdapOperationItem $item, string $status, string $message, ?CommandExecution $execution): void
    {
        $this->audit([
            'module' => 'operations.bulk_ldap',
            'action' => 'bulk_create_user_item',
            'status' => $status === 'failed' ? 'failed' : 'success',
            'target_type' => BulkLdapOperationItem::class,
            'target_key' => (string) $item->id,
            'target_dn' => $item->target_dn,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'operation_job_id' => $operation->operation_job_id,
            'command_execution_id' => $execution?->id,
            'request_payload' => [
                'bulk_ldap_operation_id' => $operation->id,
                'sequence' => $item->sequence,
                'uid' => $item->uid,
                'item_status' => $status,
            ],
            'after_value' => [
                'message' => $message,
                'stdout' => $item->stdout,
                'stderr' => $item->stderr,
                'exit_code' => $item->exit_code,
            ],
            'error_message' => $status === 'failed' ? $message : null,
        ]);
    }

    private function audit(array $payload): void
    {
        if (! class_exists(AuditLogger::class)) {
            return;
        }

        try {
            app(AuditLogger::class)->log(RedactsSensitiveData::redact($payload));
        } catch (Throwable) {
            // Audit failure must not break LDAP queue execution.
        }
    }

    private function syncDirectoryExplorerQuietly(BulkLdapOperation $operation): void
    {
        if (! class_exists(\App\Services\Directory\LdapDirectoryExplorerSyncService::class)) {
            return;
        }

        try {
            app(\App\Services\Directory\LdapDirectoryExplorerSyncService::class)->sync($operation->ldapConnection);
        } catch (Throwable) {
            // Do not fail completed bulk job because cache refresh failed.
        }
    }

    private function filterTablePayload(string $table, array $payload): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        return collect($payload)
            ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
            ->all();
    }

    private function normalizeDn(string $dn): string
    {
        return trim($dn);
    }

    private function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(password\s*[=:]\s*)([^\s]+)/i',
            '/(bind_password\s*[=:]\s*)([^\s]+)/i',
            '/(client_secret\s*[=:]\s*)([^\s]+)/i',
            '/(token\s*[=:]\s*)([^\s]+)/i',
            '/(Authorization:\s*Bearer\s+)([^\s]+)/i',
            '/(-w\s+)([^\s]+)/i',
            '/(bindpw:\s*)([^\s]+)/i',
            '/(userPassword:\s*)(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
