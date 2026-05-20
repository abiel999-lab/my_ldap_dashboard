<?php

namespace App\Jobs\Directory;

use App\Jobs\Directory\SyncLdapSchemaEntriesJob;
use App\Models\Directory\LdapConnection;
use App\Support\Directory\LdapSchemaDefinitionParser;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Throwable;

class ModifyLdapSchemaDefinitionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $ldapConnectionId,
        public string $operation,
        public string $schemaType,
        public string $schemaConfigDn,
        public string $definition,
        public ?string $oldDefinition = null,
        public ?int $commandExecutionId = null,
    ) {
        $this->onQueue('ldap');
    }

    public function handle(): void
    {
        $connection = LdapConnection::query()->findOrFail($this->ldapConnectionId);

        try {
            $attribute = LdapSchemaDefinitionParser::schemaTypeToLdapAttribute($this->schemaType);

            $ldif = $this->buildLdif($attribute);

            $tmp = tempnam(sys_get_temp_dir(), 'ldap_schema_');

            file_put_contents($tmp, $ldif);

            $command = [
                'ldapmodify',
                '-x',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->value($connection, ['schema_bind_dn', 'config_bind_dn', 'bind_dn', 'username']),
                '-w',
                $this->value($connection, ['schema_bind_password', 'config_bind_password', 'bind_password', 'password']),
                '-f',
                $tmp,
            ];

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            @unlink($tmp);

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? 0;

            $payload = [
                'operation' => $this->operation,
                'schema_type' => $this->schemaType,
                'schema_config_dn' => $this->schemaConfigDn,
                'attribute' => $attribute,
                'definition' => $this->definition,
                'old_definition' => $this->oldDefinition,
                'ldif' => $ldif,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
            ];

            if ($this->commandExecutionId) {
                if ($process->isSuccessful()) {
                    SafeCommandExecutionLogger::markSuccess($this->commandExecutionId, $payload);
                } else {
                    SafeCommandExecutionLogger::markFailed(
                        $this->commandExecutionId,
                        trim($stderr) ?: 'ldapmodify failed with exit code '.$exitCode,
                        $payload
                    );
                }
            }

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($stderr) ?: 'ldapmodify failed with exit code '.$exitCode);
            }

            SyncLdapSchemaEntriesJob::dispatch($this->ldapConnectionId, null);
        } catch (Throwable $e) {
            if ($this->commandExecutionId) {
                SafeCommandExecutionLogger::markFailed($this->commandExecutionId, $e->getMessage(), [
                    'operation' => $this->operation,
                    'schema_type' => $this->schemaType,
                    'schema_config_dn' => $this->schemaConfigDn,
                    'definition' => $this->definition,
                    'old_definition' => $this->oldDefinition,
                ]);
            }

            throw $e;
        }
    }

    private function buildLdif(string $attribute): string
    {
        $definition = trim($this->definition);
        $oldDefinition = trim((string) $this->oldDefinition);

        if ($this->operation === 'add') {
            return implode("\n", [
                'dn: '.$this->schemaConfigDn,
                'changetype: modify',
                'add: '.$attribute,
                $attribute.': '.$definition,
                '',
            ]);
        }

        if ($this->operation === 'replace') {
            if ($oldDefinition === '') {
                return implode("\n", [
                    'dn: '.$this->schemaConfigDn,
                    'changetype: modify',
                    'replace: '.$attribute,
                    $attribute.': '.$definition,
                    '',
                ]);
            }

            return implode("\n", [
                'dn: '.$this->schemaConfigDn,
                'changetype: modify',
                'delete: '.$attribute,
                $attribute.': '.$oldDefinition,
                '-',
                'add: '.$attribute,
                $attribute.': '.$definition,
                '',
            ]);
        }

        if ($this->operation === 'delete') {
            return implode("\n", [
                'dn: '.$this->schemaConfigDn,
                'changetype: modify',
                'delete: '.$attribute,
                $attribute.': '.$definition,
                '',
            ]);
        }

        throw new \InvalidArgumentException('Unsupported schema operation: '.$this->operation);
    }

    private function ldapUri($connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if (str_contains($host, '://')) {
            return $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function value($model, array $columns, string $default = ''): string
    {
        foreach ($columns as $column) {
            if (isset($model->{$column}) && $model->{$column} !== null && $model->{$column} !== '') {
                return (string) $model->{$column};
            }
        }

        return $default;
    }
}
