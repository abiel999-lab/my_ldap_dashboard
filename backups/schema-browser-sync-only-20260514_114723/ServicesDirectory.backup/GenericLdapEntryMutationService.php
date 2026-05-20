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
            ldif: $this->buildAttributeLdif((string) $record->dn, 'add', $attribute, $values),
            parentExecutionId: $parentExecutionId,
        );
    }

    public function replaceAttribute(Model $record, string $attribute, array|string $values, ?int $parentExecutionId = null): array
    {
        return $this->ldapModify(
            record: $record,
            operation: 'replace_attribute',
            ldif: $this->buildAttributeLdif((string) $record->dn, 'replace', $attribute, $values),
            parentExecutionId: $parentExecutionId,
        );
    }

    public function removeAttribute(Model $record, string $attribute, ?int $parentExecutionId = null): array
    {
        $attribute = trim($attribute);

        if ($attribute === '') {
            return [
                'ok' => false,
                'message' => 'Attribute name is required.',
            ];
        }

        return $this->ldapModify(
            record: $record,
            operation: 'remove_attribute',
            ldif: "dn: {$record->dn}\nchangetype: modify\ndelete: {$attribute}\n",
            parentExecutionId: $parentExecutionId,
        );
    }

    public function addObjectClass(Model $record, string $objectClass, array $mustAttributes = [], ?int $parentExecutionId = null): array
    {
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [
                'ok' => false,
                'message' => 'ObjectClass is required.',
            ];
        }

        $lines = [
            "dn: {$record->dn}",
            "changetype: modify",
            "add: objectClass",
            "objectClass: {$objectClass}",
            "-",
        ];

        foreach ($mustAttributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '') {
                continue;
            }

            $values = $this->normalizeValues($value);

            if ($values === []) {
                continue;
            }

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
        $objectClass = trim($objectClass);

        if ($objectClass === '') {
            return [
                'ok' => false,
                'message' => 'ObjectClass is required.',
            ];
        }

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
        $newRdnAttribute = trim($newRdnAttribute);
        $newRdnValue = trim($newRdnValue);

        if ($newRdnAttribute === '' || $newRdnValue === '') {
            return [
                'ok' => false,
                'message' => 'RDN attribute and value are required.',
            ];
        }

        $connection = $this->connectionForRecord($record);

        $oldDn = (string) $record->dn;
        $newRdn = $newRdnAttribute.'='.$newRdnValue;

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
            $newDn = $parentDn !== '' ? $newRdn.','.$parentDn : $newRdn;

            $this->updateRecordDn($record, $newDn);
        }

        return $result;
    }

    public function moveEntry(Model $record, string $newParentDn, ?int $parentExecutionId = null): array
    {
        $newParentDn = trim($newParentDn);

        if ($newParentDn === '') {
            return [
                'ok' => false,
                'message' => 'New Parent DN is required.',
            ];
        }

        $connection = $this->connectionForRecord($record);

        $oldDn = (string) $record->dn;
        $rdn = $this->rdn($oldDn);

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
            operation: 'move_entry',
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

        if ($dn === '') {
            return [
                'ok' => false,
                'message' => 'DN is empty.',
            ];
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

    public function createEntry(
        int $ldapConnectionId,
        string $dn,
        array $objectClasses,
        array $attributes,
        ?int $parentExecutionId = null,
    ): array {
        $connection = LdapConnection::query()->findOrFail($ldapConnectionId);

        $dn = trim($dn);

        if ($dn === '') {
            return [
                'ok' => false,
                'message' => 'DN is required.',
            ];
        }

        $objectClasses = collect($objectClasses)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($objectClasses === []) {
            return [
                'ok' => false,
                'message' => 'At least one objectClass is required.',
            ];
        }

        $lines = [
            'dn: '.$dn,
        ];

        foreach ($objectClasses as $objectClass) {
            $lines[] = 'objectClass: '.$objectClass;
        }

        $seenAttributeValues = [];

        foreach ($attributes as $attribute => $value) {
            $attribute = trim((string) $attribute);

            if ($attribute === '' || strtolower($attribute) === 'objectclass') {
                continue;
            }

            foreach ($this->normalizeValues($value) as $singleValue) {
                $dedupeKey = strtolower($attribute).'|'.$singleValue;

                if (isset($seenAttributeValues[$dedupeKey])) {
                    continue;
                }

                $seenAttributeValues[$dedupeKey] = true;
                $lines[] = "{$attribute}: {$singleValue}";
            }
        }

        $ldif = implode("\n", $lines)."\n";

        $tmp = tempnam(storage_path('app'), 'ldapadd_');
        file_put_contents($tmp, $ldif);

        try {
            $command = [
                'ldapadd',
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

            $display = 'ldapadd -v -x'
                .' -H '.$this->ldapUri($connection)
                .' -D '.$this->bindDn($connection)
                .' -w [REDACTED]'
                .' -f '.$tmp;

            $execution = SafeCommandExecutionLogger::createQueued(
                'ldap_generic_entry_create_item',
                $display,
                [
                    'operation' => 'create_entry',
                    'ldap_connection_id' => $ldapConnectionId,
                    'dn' => $dn,
                    'ldif' => $this->redact($ldif),
                    'parent_command_execution_id' => $parentExecutionId,
                ]
            );

            $process = new Process($command, base_path());
            $process->setTimeout(300);
            $process->run();

            $stdout = $this->redact($process->getOutput());
            $stderr = $this->redact($process->getErrorOutput());

            $payload = [
                'operation' => 'create_entry',
                'dn' => $dn,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $process->getExitCode(),
            ];

            if (! $process->isSuccessful()) {
                $message = trim($stderr ?: $stdout ?: 'ldapadd failed.');

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
                'message' => 'LDAP entry created.',
                'command_execution_id' => SafeCommandExecutionLogger::id($execution),
            ];
        } finally {
            @unlink($tmp);
        }
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

    private function runCommand(
        Model $record,
        string $operation,
        array $command,
        string $displayCommand,
        ?int $parentExecutionId = null,
        array $extraContext = [],
    ): array {
        $execution = SafeCommandExecutionLogger::createQueued(
            'ldap_generic_entry_mutation_item',
            $displayCommand,
            array_merge([
                'operation' => $operation,
                'model_class' => get_class($record),
                'record_id' => $record->id ?? null,
                'dn' => $record->dn ?? null,
                'ldap_connection_id' => $record->ldap_connection_id ?? $record->connection_id ?? null,
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
        $attribute = trim($attribute);

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

    private function normalizeValues(mixed $values): array
    {
        if (is_string($values)) {
            $values = array_map('trim', explode(',', $values));
        }

        if (! is_array($values)) {
            $values = [$values];
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
        $data = [
            'dn' => $newDn,
        ];

        if (Schema::hasColumn($record->getTable(), 'last_seen_at')) {
            $data['last_seen_at'] = now();
        }

        if (Schema::hasColumn($record->getTable(), 'updated_at')) {
            $data['updated_at'] = now();
        }

        $record->forceFill($data)->save();
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

        if (Schema::hasColumn($record->getTable(), 'updated_at')) {
            $data['updated_at'] = now();
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

        $text = preg_replace('/(-w\s+)(\S+)/', '$1[REDACTED]', $text);

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }
}
