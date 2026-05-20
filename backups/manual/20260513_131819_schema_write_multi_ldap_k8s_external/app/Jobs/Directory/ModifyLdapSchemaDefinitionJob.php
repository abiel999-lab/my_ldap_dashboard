<?php

namespace App\Jobs\Directory;

use App\Models\Directory\LdapConnection;
use App\Support\Directory\LdapSchemaDefinitionParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class ModifyLdapSchemaDefinitionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

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
        $this->markRunning();

        $connection = LdapConnection::query()->findOrFail($this->ldapConnectionId);

        $attribute = LdapSchemaDefinitionParser::schemaTypeToConfigAttribute($this->schemaType);

        $tmp = null;

        try {
            $exactOldDefinition = null;

            if (in_array($this->operation, ['replace', 'delete'], true)) {
                $exactOldDefinition = $this->findExactConfigValue(
                    $connection,
                    $this->schemaConfigDn,
                    $attribute,
                    $this->oldDefinition ?: $this->definition
                );
            }

            $ldif = $this->buildLdif($attribute, $exactOldDefinition);

            $tmp = tempnam(sys_get_temp_dir(), 'ldap_schema_modify_');
            file_put_contents($tmp, $ldif);

            $command = [
                'ldapmodify',
                '-x',
                '-H',
                $this->ldapUri($connection),
            ];

            $bindDn = $this->value($connection, [
                'schema_bind_dn',
                'config_bind_dn',
                'bind_dn',
                'username',
            ]);

            $password = $this->value($connection, [
                'schema_bind_password',
                'config_bind_password',
                'bind_password',
                'password',
            ]);

            if ($bindDn !== '') {
                $command[] = '-D';
                $command[] = $bindDn;
            }

            if ($password !== '') {
                $command[] = '-w';
                $command[] = $password;
            }

            $command[] = '-f';
            $command[] = $tmp;

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            @unlink($tmp);
            $tmp = null;

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? 0;

            $payload = [
                'operation' => $this->operation,
                'schema_type' => $this->schemaType,
                'schema_config_dn' => $this->schemaConfigDn,
                'schema_attribute' => $attribute,
                'definition' => $this->definition,
                'old_definition' => $this->oldDefinition,
                'exact_old_definition' => $exactOldDefinition,
                'ldif' => $ldif,
                'command' => $this->redactedCommand($command),
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
            ];

            if (! $process->isSuccessful()) {
                $message = trim($stderr) ?: 'ldapmodify failed with exit code '.$exitCode;

                $this->markFailed($message, $payload);

                throw new \RuntimeException($message);
            }

            $this->markSuccess($payload);

            try {
                \Artisan::call('iam:schema-sync-direct', [
                    '--connection' => (string) $this->ldapConnectionId,
                    '--reset' => '1',
                ]);
            } catch (Throwable $syncError) {
                report($syncError);
            }
        } catch (Throwable $e) {
            if ($tmp) {
                @unlink($tmp);
            }

            $this->markFailed($e->getMessage(), [
                'operation' => $this->operation,
                'schema_type' => $this->schemaType,
                'schema_config_dn' => $this->schemaConfigDn,
                'definition' => $this->definition,
                'old_definition' => $this->oldDefinition,
                'exception' => get_class($e),
            ]);

            throw $e;
        }
    }

    private function buildLdif(string $attribute, ?string $exactOldDefinition): string
    {
        $definition = trim($this->definition);
        $oldDefinition = trim((string) ($exactOldDefinition ?: $this->oldDefinition));

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
                    'add: '.$attribute,
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
            if ($oldDefinition === '') {
                $oldDefinition = $definition;
            }

            return implode("\n", [
                'dn: '.$this->schemaConfigDn,
                'changetype: modify',
                'delete: '.$attribute,
                $attribute.': '.$oldDefinition,
                '',
            ]);
        }

        throw new \InvalidArgumentException('Unsupported schema operation: '.$this->operation);
    }

    private function findExactConfigValue($connection, string $schemaConfigDn, string $attribute, string $targetDefinition): ?string
    {
        $targetDefinition = LdapSchemaDefinitionParser::cleanDefinition($targetDefinition);
        $targetMeta = LdapSchemaDefinitionParser::parse($this->schemaType, $targetDefinition);

        $targetOid = $targetMeta['oid'] ?? null;
        $targetPrimaryName = $targetMeta['primary_name'] ?? null;

        $command = [
            'ldapsearch',
            '-LLL',
            '-o',
            'ldif-wrap=no',
            '-x',
            '-H',
            $this->ldapUri($connection),
        ];

        $bindDn = $this->value($connection, [
            'schema_bind_dn',
            'config_bind_dn',
            'bind_dn',
            'username',
        ]);

        $password = $this->value($connection, [
            'schema_bind_password',
            'config_bind_password',
            'bind_password',
            'password',
        ]);

        if ($bindDn !== '') {
            $command[] = '-D';
            $command[] = $bindDn;
        }

        if ($password !== '') {
            $command[] = '-w';
            $command[] = $password;
        }

        $command = array_merge($command, [
            '-b',
            $schemaConfigDn,
            '-s',
            'base',
            '(objectClass=*)',
            $attribute,
        ]);

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $values = $this->parseConfigValues($process->getOutput(), $attribute);

        foreach ($values as $value) {
            $clean = LdapSchemaDefinitionParser::cleanDefinition($value);
            $meta = LdapSchemaDefinitionParser::parse($this->schemaType, $clean);

            $oid = $meta['oid'] ?? null;
            $primaryName = $meta['primary_name'] ?? null;

            if ($targetOid && $oid && $targetOid === $oid) {
                return $value;
            }

            if ($targetPrimaryName && $primaryName && $targetPrimaryName === $primaryName) {
                return $value;
            }

            if ($clean === $targetDefinition) {
                return $value;
            }
        }

        return null;
    }

    private function parseConfigValues(string $output, string $attribute): array
    {
        $output = $this->unfoldLdif($output);

        $values = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim((string) $line);

            if (str_starts_with($line, $attribute.':')) {
                $values[] = trim(substr($line, strlen($attribute) + 1));
            }
        }

        return $values;
    }

    private function unfoldLdif(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') && $result !== []) {
                $result[count($result) - 1] .= substr($line, 1);
                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function ldapUri($connection): string
    {
        $scheme = $this->value($connection, ['scheme', 'protocol'], 'ldap');
        $host = $this->value($connection, ['host', 'hostname', 'server']);
        $port = $this->value($connection, ['port'], '389');

        if ($host !== '' && str_contains($host, '://')) {
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

    private function redactedCommand(array $command): string
    {
        $copy = $command;

        foreach ($copy as $i => $part) {
            if ($part === '-w' && isset($copy[$i + 1])) {
                $copy[$i + 1] = '[REDACTED]';
            }
        }

        return implode(' ', array_map('escapeshellarg', $copy));
    }

    private function markRunning(): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'running',
                'updated_at' => now(),
            ]);
    }

    private function markSuccess(array $payload): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'success',
                'stdout' => $payload['stdout'] ?? null,
                'stderr' => $payload['stderr'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 0,
                'error_message' => null,
                'environment_context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markFailed(string $message, array $payload): void
    {
        if (! $this->commandExecutionId) {
            return;
        }

        DB::table('command_executions')
            ->where('id', $this->commandExecutionId)
            ->update([
                'status' => 'failed',
                'stdout' => $payload['stdout'] ?? null,
                'stderr' => $payload['stderr'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 1,
                'error_message' => $message,
                'environment_context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
