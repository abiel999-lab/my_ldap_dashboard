<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class LdapUserMutationService
{
    public function addAttribute(LdapUserEntry $user, string $attribute, array|string $values): array
    {
        $attribute = $this->cleanAttributeName($attribute);
        $values = $this->cleanValues($values);

        if ($attribute === '') {
            return $this->failed('Attribute name is required.');
        }

        if ($values === []) {
            return $this->failed('At least one value is required.');
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'add',
                'attribute' => $attribute,
                'values' => $values,
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_add_attribute');

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        $existing = $this->values($attributes[$attribute] ?? []);

        $attributes[$attribute] = collect([...$existing, ...$values])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute added successfully.',
        ];
    }

    public function replaceAttribute(LdapUserEntry $user, string $attribute, array|string $values): array
    {
        $attribute = $this->cleanAttributeName($attribute);
        $values = $this->cleanValues($values);

        if ($attribute === '') {
            return $this->failed('Attribute name is required.');
        }

        if ($values === []) {
            return $this->failed('At least one value is required.');
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'replace',
                'attribute' => $attribute,
                'values' => $values,
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_replace_attribute');

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);
        $attributes[$attribute] = $values;

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute replaced successfully.',
        ];
    }

    public function removeAttribute(LdapUserEntry $user, string $attribute): array
    {
        $attribute = $this->cleanAttributeName($attribute);

        if ($attribute === '') {
            return $this->failed('Attribute name is required.');
        }

        if (in_array(strtolower($attribute), ['dn', 'uid'], true)) {
            return $this->failed('This attribute is protected and cannot be removed from here.');
        }

        $ldif = $this->buildModifyLdif($user->dn, [
            [
                'operation' => 'delete',
                'attribute' => $attribute,
                'values' => [],
            ],
        ]);

        $result = $this->applyLdif($user, $ldif, 'ldap_user_remove_attribute');

        if (! $result['ok']) {
            return $result;
        }

        $attributes = $this->normalAttributes($user);

        unset($attributes[$attribute]);

        $this->saveNormalAttributes($user, $attributes);

        return $result + [
            'message' => 'Attribute removed successfully.',
        ];
    }

    private function applyLdif(LdapUserEntry $user, string $ldif, string $commandType): array
    {
        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        $tmpDir = storage_path('app/private/ldap-mutations/users');
        @mkdir($tmpDir, 0775, true);

        $tmpFile = $tmpDir.'/'.date('Ymd_His').'_'.Str::slug($commandType).'_'.$user->id.'.ldif';

        file_put_contents($tmpFile, $ldif);

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
            $tmpFile,
        ];

        $displayCommand = 'ldapmodify -v -x'
            .' -H '.$this->ldapUri($connection)
            .' -D '.$this->bindDn($connection)
            .' -w [REDACTED]'
            .' -f '.$tmpFile;

        $execution = CommandExecution::query()->create([
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $displayCommand,
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
                'ldif_file' => $tmpFile,
            ],
            'started_at' => now(),
        ]);

        try {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $execution->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'exit_code' => $process->getExitCode(),
                'stdout' => $this->redactSensitiveText($stdout),
                'stderr' => $this->redactSensitiveText($stderr),
                'error_message' => $process->isSuccessful() ? null : trim($stderr ?: $stdout ?: 'ldapmodify failed.'),
                'finished_at' => now(),
            ]);

            if (! $process->isSuccessful()) {
                return $this->failed(
                    trim($stderr ?: $stdout ?: 'ldapmodify failed.'),
                    $execution->id
                );
            }

            return [
                'ok' => true,
                'message' => 'LDAP modify succeeded.',
                'command_execution_id' => $execution->id,
                'ldif_file' => $tmpFile,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'exit_code' => 1,
                'stdout' => null,
                'stderr' => $this->redactSensitiveText($e->getMessage()),
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $this->failed($e->getMessage(), $execution->id);
        }
    }

    private function buildModifyLdif(string $dn, array $changes): string
    {
        if (! filled($dn)) {
            throw new RuntimeException('DN is required.');
        }

        $lines = [
            'dn: '.$dn,
            'changetype: modify',
        ];

        foreach ($changes as $index => $change) {
            $operation = $change['operation'];
            $attribute = $change['attribute'];
            $values = $change['values'] ?? [];

            if ($index > 0) {
                $lines[] = '-';
            }

            $lines[] = $operation.': '.$attribute;

            foreach ($values as $value) {
                $lines[] = $attribute.': '.$this->formatLdifValue((string) $value);
            }
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function formatLdifValue(string $value): string
    {
        return $value;
    }

    private function normalAttributes(LdapUserEntry $user): array
    {
        $raw = $user->getRawOriginal('attributes');

        if (is_array($user->attributes ?? null)) {
            return $user->attributes;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function saveNormalAttributes(LdapUserEntry $user, array $attributes): void
    {
        ksort($attributes, SORT_NATURAL | SORT_FLAG_CASE);

        $user->forceFill([
            'attributes' => $attributes,
            'source_hash' => hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'last_synced_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $user->refresh();
    }

    private function cleanAttributeName(string $attribute): string
    {
        $attribute = trim($attribute);

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $attribute)) {
            return '';
        }

        return $attribute;
    }

    private function cleanValues(array|string $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/\r\n|\r|\n|,/', $values) ?: [];
        }

        return collect($values)
            ->flatten()
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function values(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->values($decoded);
            }

            return [$value];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        return [(string) $value];
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

    private function redactSensitiveText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/(userPassword:\s*)(.+)/i', '$1[PROTECTED VALUE]', $text);
    }

    private function failed(string $message, ?int $commandExecutionId = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'command_execution_id' => $commandExecutionId,
        ];
    }
}
