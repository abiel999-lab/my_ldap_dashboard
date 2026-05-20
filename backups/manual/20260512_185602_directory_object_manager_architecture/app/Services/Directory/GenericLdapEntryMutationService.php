<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Support\Operations\SafeCommandExecutionLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class GenericLdapEntryMutationService
{
    public function addAttribute(Model $record, string $attribute, array|string $values, ?int $parentExecutionId = null): array
    {
        return $this->ldapModify(
            record: $record,
            operation: 'add_attribute',
            ldif: $this->buildAttributeLdif($record->dn, 'add', $attribute, $values),
            parentExecutionId: $parentExecutionId,
        );
    }

    public function replaceAttribute(Model $record, string $attribute, array|string $values, ?int $parentExecutionId = null): array
    {
        return $this->ldapModify(
            record: $record,
            operation: 'replace_attribute',
            ldif: $this->buildAttributeLdif($record->dn, 'replace', $attribute, $values),
            parentExecutionId: $parentExecutionId,
        );
    }

    public function removeAttribute(Model $record, string $attribute, ?int $parentExecutionId = null): array
    {
        return $this->ldapModify(
            record: $record,
            operation: 'remove_attribute',
            ldif: "dn: {$record->dn}\nchangetype: modify\ndelete: {$attribute}\n",
            parentExecutionId: $parentExecutionId,
        );
    }

    public function addObjectClass(Model $record, string $objectClass, array $mustAttributes = [], ?int $parentExecutionId = null): array
    {
        $lines = [
            "dn: {$record->dn}",
            "changetype: modify",
            "add: objectClass",
            "objectClass: {$objectClass}",
            "-",
        ];

        foreach ($mustAttributes as $attribute => $value) {
            $values = $this->normalizeValues($value);

            $lines[] = "add: {$attribute}";

            foreach ($values as $singleValue) {
                $lines[] = "{$attribute}: {$singleValue}";
            }

            $lines[] = "-";
        }

        return $this->ldapModify(
            record: $record,
            operation: 'add_objectclass',
            ldif: implode("\n", $lines)."\n",
            parentExecutionId: $parentExecutionId,
        );
    }

    public function removeObjectClass(Model $record, string $objectClass, array $removeAttributes = [], ?int $parentExecutionId = null): array
    {
        $lines = [
            "dn: {$record->dn}",
            "changetype: modify",
        ];

        foreach ($removeAttributes as $attribute) {
            $attribute = trim((string) $attribute);

            if ($attribute === '') {
                continue;
            }

            $lines[] = "delete: {$attribute}";
            $lines[] = "-";
        }

        $lines[] = "delete: objectClass";
        $lines[] = "objectClass: {$objectClass}";

        return $this->ldapModify(
            record: $record,
            operation: 'remove_objectclass',
            ldif: implode("\n", $lines)."\n",
            parentExecutionId: $parentExecutionId,
        );
    }

    public function renameRdn(Model $record, string $newRdnAttribute, string $newRdnValue, bool $deleteOldRdn = true, ?int $parentExecutionId = null): array
    {
        $connection = $this->connectionForRecord($record);

        $oldDn = (string) $record->dn;
        $newRdn = trim($newRdnAttribute).'='.trim($newRdnValue);

        $command = [
            'ldapmodrdn',
            '-v',
            '-x',
            '-H',
            $this->ldapUri($connection),
            '-D',
            $this->bindDn($connection),
            '-w',
            $this->bindPassword($connection),
        ];

        if ($deleteOldRdn) {
            $command[] = '-r';
        }

        $command[] = $oldDn;
        $command[] = $newRdn;

        $display = 'ldapmodrdn -v -x'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED] '
            .($deleteOldRdn ? '-r ' : '')
            .$oldDn.' '.$newRdn;

        $result = $this->runCommand(
            record: $record,
            operation: 'rename_rdn',
            command: $command,
            displayCommand: $display,
            parentExecutionId: $parentExecutionId,
        );

        if ($result['ok'] ?? false) {
            $parentDn = $this->parentDn($oldDn);
            $newDn = $newRdn.','.$parentDn;

            $this->updateRecordDn($record, $newDn);
        }

        return $result;
    }

    public function moveOu(Model $record, string $newParentDn, ?int $parentExecutionId = null): array
    {
        $connection = $this->connectionForRecord($record);

        $oldDn = (string) $record->dn;
        $rdn = $this->rdn($oldDn);
        $newParentDn = trim($newParentDn);

        $command = [
            'ldapmodrdn',
            '-v',
            '-x',
            '-H',
            $this->ldapUri($connection),
            '-D',
            $this->bindDn($connection),
            '-w',
            $this->bindPassword($connection),
            '-s',
            $newParentDn,
            $oldDn,
            $rdn,
        ];

        $display = 'ldapmodrdn -v -x'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]'
            .' -s '.$newParentDn.' '.$oldDn.' '.$rdn;

        $result = $this->runCommand(
            record: $record,
            operation: 'move_ou',
            command: $command,
            displayCommand: $display,
            parentExecutionId: $parentExecutionId,
        );

        if ($result['ok'] ?? false) {
            $this->updateRecordDn($record, $rdn.','.$newParentDn);
        }

        return $result;
    }

    public function deleteEntry(Model $record, ?int $parentExecutionId = null): array
    {
        $connection = $this->connectionForRecord($record);
        $dn = (string) $record->dn;

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

        $display = 'ldapdelete -v -x'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED] '
            .$dn;

        $result = $this->runCommand(
            record: $record,
            operation: 'delete_entry',
            command: $command,
            displayCommand: $display,
            parentExecutionId: $parentExecutionId,
        );

        if ($result['ok'] ?? false) {
            $this->markDeleted($record);
        }

        return $result;
    }

    private function ldapModify(Model $record, string $operation, string $ldif, ?int $parentExecutionId = null): array
    {
        $connection = $this->connectionForRecord($record);

        $tmp = tempnam(storage_path('app'), 'ldapmodify_');
        file_put_contents($tmp, $ldif);

        try {
            $command = [
                'ldapmodify',
                '-v',
                '-x',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                '-f',
                $tmp,
            ];

            $display = 'ldapmodify -v -x'
                .' -H '.$this->ldapUri($connection)
                .' -D '.$this->bindDn($connection)
                .' -w [REDACTED]'
                .' -f '.$tmp;

            return $this->runCommand(
                record: $record,
                operation: $operation,
                command: $command,
                displayCommand: $display,
                parentExecutionId: $parentExecutionId,
                extraContext: [
                    'ldif' => $this->redact($ldif),
                ],
            );
        } finally {
            @unlink($tmp);
        }
    }

    private function runCommand(Model $record, string $operation, array $command, string $displayCommand, ?int $parentExecutionId = null, array $extraContext = []): array
    {
        $execution = SafeCommandExecutionLogger::createQueued(
            'ldap_generic_entry_mutation_item',
            $displayCommand,
            array_merge([
                'operation' => $operation,
                'model_class' => get_class($record),
                'record_id' => $record->id ?? null,
                'dn' => $record->dn ?? null,
                'parent_command_execution_id' => $parentExecutionId,
            ], $extraContext)
        );

        $process = new Process($command, base_path());
        $process->setTimeout(300);
        $process->run();

        $stdout = $this->redact($process->getOutput());
        $stderr = $this->redact($process->getErrorOutput());

        $payload = [
            'operation' => $operation,
            'record_id' => $record->id ?? null,
            'dn' => $record->dn ?? null,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $process->getExitCode(),
        ];

        if (! $process->isSuccessful()) {
            $message = trim($stderr ?: $stdout ?: 'LDAP command failed.');

            SafeCommandExecutionLogger::markFailed(
                SafeCommandExecutionLogger::id($execution),
                $message,
                $payload,
                $payload
            );

            return [
                'ok' => false,
                'message' => $message,
                'command_execution_id' => SafeCommandExecutionLogger::id($execution),
            ];
        }

        SafeCommandExecutionLogger::markSuccess(
            SafeCommandExecutionLogger::id($execution),
            $payload,
            $payload
        );

        return [
            'ok' => true,
            'message' => 'LDAP operation successful.',
            'command_execution_id' => SafeCommandExecutionLogger::id($execution),
        ];
    }

    private function buildAttributeLdif(string $dn, string $change, string $attribute, array|string $values): string
    {
        $lines = [
            "dn: {$dn}",
            'changetype: modify',
            "{$change}: {$attribute}",
        ];

        foreach ($this->normalizeValues($values) as $value) {
            $lines[] = "{$attribute}: {$value}";
        }

        return implode("\n", $lines)."\n";
    }

    private function normalizeValues(array|string $values): array
    {
        if (is_string($values)) {
            $values = array_map('trim', explode(',', $values));
        }

        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    private function connectionForRecord(Model $record): LdapConnection
    {
        $connectionId = $record->ldap_connection_id ?? $record->connection_id ?? null;

        if ($connectionId) {
            $connection = LdapConnection::query()->find($connectionId);

            if ($connection) {
                return $connection;
            }
        }

        return LdapConnection::query()->orderBy('id')->firstOrFail();
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? $connection->ldap_host ?? '127.0.0.1';
        $port = $connection->port ?? $connection->ldap_port ?? 389;
        $scheme = $connection->scheme ?? $connection->protocol ?? 'ldap';

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) (
            $connection->bind_dn
            ?? $connection->admin_dn
            ?? $connection->username
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

    private function rdn(string $dn): string
    {
        return explode(',', $dn, 2)[0] ?? $dn;
    }

    private function parentDn(string $dn): string
    {
        return explode(',', $dn, 2)[1] ?? '';
    }

    private function updateRecordDn(Model $record, string $newDn): void
    {
        $record->forceFill([
            'dn' => $newDn,
            'last_seen_at' => now(),
        ])->save();
    }

    private function markDeleted(Model $record): void
    {
        $data = [];

        if (Schema::hasColumn($record->getTable(), 'status')) {
            $data['status'] = 'deleted_from_ldap';
        }

        if (Schema::hasColumn($record->getTable(), 'last_seen_at')) {
            $data['last_seen_at'] = now();
        }

        if ($data !== []) {
            $record->forceFill($data)->save();
            return;
        }

        $record->delete();
    }

    private function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }
}
