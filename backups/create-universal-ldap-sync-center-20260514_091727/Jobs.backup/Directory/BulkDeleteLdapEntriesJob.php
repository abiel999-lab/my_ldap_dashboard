<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class BulkDeleteLdapEntriesJob implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $modelClass,
        public array $recordIds,
        public ?int $commandExecutionId = null,
        public string $label = 'LDAP entries',
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        SafeCommandExecutionLogger::markRunning($this->commandExecutionId);

        $ok = 0;
        $failed = 0;
        $results = [];

        try {
            if (! class_exists($this->modelClass)) {
                throw new \RuntimeException('Model class does not exist: '.$this->modelClass);
            }

            $model = new $this->modelClass();

            $this->modelClass::query()
                ->whereIn($model->getKeyName(), $this->recordIds)
                ->orderBy($model->getKeyName())
                ->chunkById(25, function ($records) use (&$ok, &$failed, &$results): void {
                    foreach ($records as $record) {
                        $dn = (string) ($record->dn ?? '');

                        try {
                            if ($dn === '') {
                                throw new \RuntimeException('Record has no DN.');
                            }

                            $connection = $this->connectionForRecord($record);

                            if (! $connection) {
                                throw new \RuntimeException('LDAP connection not found for record ID '.$record->id.'.');
                            }

                            $command = [
                                'ldapdelete',
                                '-v',
                                '-x',
                                '-H',
                                $this->ldapUri($connection),
                                '-D',
                                $this->bindDn($connection),
                                '-w',
                                $this->bindPassword($connection),
                                $dn,
                            ];

                            $displayCommand = 'ldapdelete -v -x'
                                .' -H '.$this->ldapUri($connection)
                                .' -D '.$this->bindDn($connection)
                                .' -w [REDACTED] '
                                .$dn;

                            $child = SafeCommandExecutionLogger::createQueued(
                                'ldap_bulk_entry_delete_item',
                                $displayCommand,
                                [
                                    'operation' => 'delete_ldap_entry_item',
                                    'model_class' => $this->modelClass,
                                    'record_id' => $record->id ?? null,
                                    'dn' => $dn,
                                    'ldap_connection_id' => $connection->id ?? null,
                                    'parent_command_execution_id' => $this->commandExecutionId,
                                ]
                            );

                            $process = new Process($command, base_path());
                            $process->setTimeout(300);
                            $process->run();

                            $stdout = $this->redact($process->getOutput());
                            $stderr = $this->redact($process->getErrorOutput());

                            if (! $process->isSuccessful()) {
                                $message = trim($stderr ?: $stdout ?: 'ldapdelete failed.');

                                SafeCommandExecutionLogger::markFailed(
                                    SafeCommandExecutionLogger::id($child),
                                    $message,
                                    [
                                        'stdout' => $stdout,
                                        'stderr' => $stderr,
                                        'exit_code' => $process->getExitCode(),
                                    ],
                                    [
                                        'operation' => 'delete_ldap_entry_item',
                                        'dn' => $dn,
                                        'record_id' => $record->id ?? null,
                                    ]
                                );

                                throw new \RuntimeException($message);
                            }

                            $this->markRecordDeleted($record);

                            SafeCommandExecutionLogger::markSuccess(
                                SafeCommandExecutionLogger::id($child),
                                [
                                    'stdout' => $stdout,
                                    'stderr' => $stderr,
                                    'exit_code' => $process->getExitCode(),
                                ],
                                [
                                    'operation' => 'delete_ldap_entry_item',
                                    'dn' => $dn,
                                    'record_id' => $record->id ?? null,
                                ]
                            );

                            $ok++;

                            $results[] = [
                                'record_id' => $record->id ?? null,
                                'dn' => $dn,
                                'ok' => true,
                                'message' => 'Deleted from LDAP.',
                                'child_command_execution_id' => SafeCommandExecutionLogger::id($child),
                            ];
                        } catch (Throwable $e) {
                            $failed++;

                            $results[] = [
                                'record_id' => $record->id ?? null,
                                'dn' => $dn,
                                'ok' => false,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                });

            $summary = [
                'operation' => 'bulk_delete_ldap_entries',
                'label' => $this->label,
                'model_class' => $this->modelClass,
                'total' => count($this->recordIds),
                'success' => $ok,
                'failed' => $failed,
                'results' => $results,
            ];

            if ($failed > 0) {
                SafeCommandExecutionLogger::markPartial(
                    $this->commandExecutionId,
                    $summary,
                    'Some LDAP entries failed to delete.',
                    $summary
                );

                return;
            }

            SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $summary, $summary);
        } catch (Throwable $e) {
            SafeCommandExecutionLogger::markFailed(
                $this->commandExecutionId,
                $e->getMessage(),
                [
                    'operation' => 'bulk_delete_ldap_entries',
                    'model_class' => $this->modelClass,
                    'record_ids' => $this->recordIds,
                ]
            );

            throw $e;
        }
    }

    private function connectionForRecord(object $record): ?LdapConnection
    {
        $connectionId = $record->ldap_connection_id
            ?? $record->connection_id
            ?? null;

        if (! $connectionId) {
            return LdapConnection::query()->orderBy('id')->first();
        }

        return LdapConnection::query()->find($connectionId);
    }

    private function markRecordDeleted(object $record): void
    {
        $table = $record->getTable();
        $data = [];

        if (Schema::hasColumn($table, 'status')) {
            $data['status'] = 'deleted_from_ldap';
        }

        if (Schema::hasColumn($table, 'last_synced_at')) {
            $data['last_synced_at'] = now();
        }

        if (Schema::hasColumn($table, 'last_seen_at')) {
            $data['last_seen_at'] = now();
        }

        if (Schema::hasColumn($table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        if ($data !== []) {
            $record->forceFill($data)->save();

            return;
        }

        $record->delete();
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? '127.0.0.1';
        $port = $connection->port ?? $connection->ldap_port ?? 389;
        $scheme = $connection->scheme ?? 'ldap';

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_dn
            ?? $connection->username
            ?? $connection->user_dn
            ?? 'cn=admin,dc=petra,dc=ac,dc=id'
        );
    }

    private function bindPassword(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_password
            ?? $connection->password
            ?? $connection->bind_pass
            ?? ''
        );
    }

    private function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }
}
