<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapSchemaEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapSchemaSyncService
{
    public function sync(?LdapConnection $connection = null): array
    {
        $startedAt = microtime(true);
        $actor = Auth::user();

        $connection = $connection ?: LdapConnection::query()->where('is_default', true)->first();

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'directory.schema',
            'command_type' => 'ldap_schema_sync',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'schema_base_dn' => 'cn=subschema',
                'read_only' => true,
                'ldap_will_change' => false,
            ]),
            'safe_mode' => true,
            'preview_mode' => true,
            'destructive' => false,
            'started_at' => now(),
        ]);

        if (! $connection) {
            $execution->forceFill([
                'status' => 'blocked',
                'stderr' => 'No default LDAP connection found.',
                'exit_code' => 126,
                'error_message' => 'No default LDAP connection found.',
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            return [
                'ok' => false,
                'message' => 'No default LDAP connection found.',
                'seen' => 0,
                'created' => 0,
                'updated' => 0,
                'command_execution_id' => $execution->id,
            ];
        }

        try {
            $process = new Process($this->buildCommand($connection), base_path());
            $process->setTimeout(180);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => 'LDAP schema sync ldapsearch failed.',
                    'finished_at' => now(),
                    'duration_ms' => $this->durationMs($startedAt),
                ])->save();

                $this->audit($connection, $execution, 'failed', [
                    'seen' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'ldap_was_changed' => false,
                ]);

                return [
                    'ok' => false,
                    'message' => 'LDAP schema sync failed.',
                    'seen' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'command_execution_id' => $execution->id,
                ];
            }

            $entry = $this->parseSubschemaEntry($stdout);

            $definitions = collect();

            foreach ($entry['objectClasses'] ?? [] as $definition) {
                $definitions->push($this->parseObjectClassDefinition($definition));
            }

            foreach ($entry['attributeTypes'] ?? [] as $definition) {
                $definitions->push($this->parseAttributeTypeDefinition($definition));
            }

            $created = 0;
            $updated = 0;
            $seenKeys = [];

            foreach ($definitions as $normalized) {
                if (blank($normalized['oid'])) {
                    continue;
                }

                $seenKeys[] = $normalized['schema_type'].'|'.$normalized['oid'];

                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapSchemaEntry::query()->firstOrNew([
                    'ldap_connection_id' => $connection->id,
                    'schema_type' => $normalized['schema_type'],
                    'oid' => $normalized['oid'],
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
                    'name' => $normalized['name'],
                    'display_name' => $normalized['display_name'],
                    'description' => $normalized['description'],
                    'superior' => $normalized['superior'],
                    'kind' => $normalized['kind'],
                    'is_single_value' => $normalized['is_single_value'],
                    'is_obsolete' => $normalized['is_obsolete'],
                    'is_operational' => $normalized['is_operational'],
                    'syntax_oid' => $normalized['syntax_oid'],
                    'equality_rule' => $normalized['equality_rule'],
                    'ordering_rule' => $normalized['ordering_rule'],
                    'substr_rule' => $normalized['substr_rule'],
                    'names' => $normalized['names'],
                    'must_attributes' => $normalized['must_attributes'],
                    'may_attributes' => $normalized['may_attributes'],
                    'extensions' => $normalized['extensions'],
                    'metadata' => $normalized['metadata'],
                    'raw_definition' => $normalized['raw_definition'],
                    'source' => 'ldap_subschema',
                    'status' => 'active',
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapSchemaEntry::query()
                ->where('ldap_connection_id', $connection->id)
                ->where('source', 'ldap_subschema')
                ->get()
                ->filter(function (LdapSchemaEntry $schema) use ($seenKeys): bool {
                    return ! in_array($schema->schema_type.'|'.$schema->oid, $seenKeys, true);
                })
                ->each(function (LdapSchemaEntry $schema): void {
                    $schema->forceFill([
                        'status' => 'missing_from_ldap',
                        'last_synced_at' => now(),
                    ])->save();
                });

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP schema synced. Seen: '.count($seenKeys).', Created: '.$created.', Updated: '.$updated.'.',
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($connection, $execution, 'success', [
                'seen' => count($seenKeys),
                'created' => $created,
                'updated' => $updated,
                'object_classes' => count($entry['objectClasses'] ?? []),
                'attribute_types' => count($entry['attributeTypes'] ?? []),
                'ldap_was_changed' => false,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP schema synced successfully.',
                'seen' => count($seenKeys),
                'created' => $created,
                'updated' => $updated,
                'command_execution_id' => $execution->id,
            ];
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stdout' => null,
                'stderr' => $this->redactString($exception->getMessage()),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($connection, $execution, 'failed', [
                'seen' => 0,
                'created' => 0,
                'updated' => 0,
                'ldap_was_changed' => false,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'seen' => 0,
                'created' => 0,
                'updated' => 0,
                'command_execution_id' => $execution->id,
            ];
        }
    }

    private function buildCommand(LdapConnection $connection): array
    {
        return [
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
            'cn=subschema',
            '-s',
            'base',
            '(objectClass=subschema)',
            'objectClasses',
            'attributeTypes',
            'ldapSyntaxes',
            'matchingRules',
        ];
    }

    private function displayCommand(LdapConnection $connection): string
    {
        return 'ldapsearch -LLL -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -b cn=subschema -s base "(objectClass=subschema)"'
            .' objectClasses attributeTypes ldapSyntaxes matchingRules';
    }

    private function parseSubschemaEntry(string $content): array
    {
        $lines = preg_split("/\R/", $content) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ' ') && $unfolded !== []) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
                continue;
            }

            $unfolded[] = $line;
        }

        $entry = [];

        foreach ($unfolded as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (! isset($entry[$key])) {
                $entry[$key] = [];
            }

            $entry[$key][] = $value;
        }

        return $entry;
    }

    private function parseObjectClassDefinition(string $definition): array
    {
        $oid = $this->firstTokenInsideDefinition($definition);
        $names = $this->extractNames($definition);
        $name = $names[0] ?? $oid;

        $kind = match (true) {
            preg_match('/\bSTRUCTURAL\b/i', $definition) === 1 => 'structural',
            preg_match('/\bAUXILIARY\b/i', $definition) === 1 => 'auxiliary',
            preg_match('/\bABSTRACT\b/i', $definition) === 1 => 'abstract',
            default => 'object_class',
        };

        return [
            'schema_type' => 'object_class',
            'oid' => $oid,
            'name' => $name,
            'display_name' => $this->humanize($name),
            'description' => $this->extractQuotedAfterKeyword($definition, 'DESC'),
            'superior' => $this->extractTokenAfterKeyword($definition, 'SUP'),
            'kind' => $kind,
            'is_single_value' => false,
            'is_obsolete' => preg_match('/\bOBSOLETE\b/i', $definition) === 1,
            'is_operational' => false,
            'syntax_oid' => null,
            'equality_rule' => null,
            'ordering_rule' => null,
            'substr_rule' => null,
            'names' => $names,
            'must_attributes' => $this->extractAttributeList($definition, 'MUST'),
            'may_attributes' => $this->extractAttributeList($definition, 'MAY'),
            'extensions' => $this->extractExtensions($definition),
            'metadata' => [
                'parser' => 'foundation_regex',
                'schema_family' => 'objectClasses',
                'ldap_was_changed' => false,
            ],
            'raw_definition' => $definition,
        ];
    }

    private function parseAttributeTypeDefinition(string $definition): array
    {
        $oid = $this->firstTokenInsideDefinition($definition);
        $names = $this->extractNames($definition);
        $name = $names[0] ?? $oid;

        $usage = $this->extractTokenAfterKeyword($definition, 'USAGE');
        $isOperational = filled($usage) && $usage !== 'userApplications';

        return [
            'schema_type' => 'attribute_type',
            'oid' => $oid,
            'name' => $name,
            'display_name' => $this->humanize($name),
            'description' => $this->extractQuotedAfterKeyword($definition, 'DESC'),
            'superior' => $this->extractTokenAfterKeyword($definition, 'SUP'),
            'kind' => $isOperational ? 'operational_attribute' : 'user_attribute',
            'is_single_value' => preg_match('/\bSINGLE-VALUE\b/i', $definition) === 1,
            'is_obsolete' => preg_match('/\bOBSOLETE\b/i', $definition) === 1,
            'is_operational' => $isOperational,
            'syntax_oid' => $this->extractTokenAfterKeyword($definition, 'SYNTAX'),
            'equality_rule' => $this->extractTokenAfterKeyword($definition, 'EQUALITY'),
            'ordering_rule' => $this->extractTokenAfterKeyword($definition, 'ORDERING'),
            'substr_rule' => $this->extractTokenAfterKeyword($definition, 'SUBSTR'),
            'names' => $names,
            'must_attributes' => [],
            'may_attributes' => [],
            'extensions' => $this->extractExtensions($definition),
            'metadata' => [
                'parser' => 'foundation_regex',
                'schema_family' => 'attributeTypes',
                'usage' => $usage,
                'ldap_was_changed' => false,
            ],
            'raw_definition' => $definition,
        ];
    }

    private function firstTokenInsideDefinition(string $definition): ?string
    {
        if (preg_match('/^\(\s*([^\s]+)/', $definition, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function extractNames(string $definition): array
    {
        if (preg_match('/\bNAME\s+\(\s*([^)]+)\)/i', $definition, $matches) === 1) {
            preg_match_all("/'([^']+)'/", $matches[1], $nameMatches);

            return collect($nameMatches[1] ?? [])
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        if (preg_match("/\bNAME\s+'([^']+)'/i", $definition, $matches) === 1) {
            return [trim($matches[1])];
        }

        return [];
    }

    private function extractQuotedAfterKeyword(string $definition, string $keyword): ?string
    {
        if (preg_match("/\b".preg_quote($keyword, '/')."\s+'([^']*)'/i", $definition, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    private function extractTokenAfterKeyword(string $definition, string $keyword): ?string
    {
        if (preg_match("/\b".preg_quote($keyword, '/')."\s+([^\s\)]+)/i", $definition, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    private function extractAttributeList(string $definition, string $keyword): array
    {
        if (preg_match('/\b'.preg_quote($keyword, '/').'\s+\(\s*([^)]+)\)/i', $definition, $matches) === 1) {
            return collect(preg_split('/\s*\$\s*/', trim($matches[1])) ?: [])
                ->map(fn ($item): string => trim($item, " \t\n\r\0\x0B'"))
                ->filter()
                ->values()
                ->all();
        }

        if (preg_match('/\b'.preg_quote($keyword, '/').'\s+([^\s\)]+)/i', $definition, $matches) === 1) {
            return [trim($matches[1], " \t\n\r\0\x0B'")];
        }

        return [];
    }

    private function extractExtensions(string $definition): array
    {
        $extensions = [];

        if (preg_match_all('/\b(X-[A-Z0-9-]+)\s+(?:\(\s*([^)]+)\)|\'([^\']+)\'|([^\s\)]+))/i', $definition, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1] ?? null;

                if (! $key) {
                    continue;
                }

                $raw = $match[2] ?: ($match[3] ?: ($match[4] ?? ''));

                preg_match_all("/'([^']+)'/", $raw, $quoted);

                $extensions[$key] = ($quoted[1] ?? []) !== []
                    ? $quoted[1]
                    : trim($raw);
            }
        }

        return $extensions;
    }

    private function humanize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'LDAP Schema Entry';
        }

        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?: $value;

        return str($value)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function audit(?LdapConnection $connection, CommandExecution $execution, string $status, array $summary): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.schema',
            'action' => 'sync_ldap_schema',
            'status' => $status,
            'target_type' => LdapConnection::class,
            'target_key' => (string) ($connection?->id ?? 'N/A'),
            'target_dn' => 'cn=subschema',
            'ldap_connection_id' => $connection?->id,
            'request_payload' => [
                'base_dn' => 'cn=subschema',
                'read_only' => true,
                'ldap_was_changed' => false,
            ],
            'after_value' => $summary,
            'command' => $execution->command,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'exit_code' => $execution->exit_code,
            'error_message' => $execution->error_message,
            'duration_ms' => $execution->duration_ms,
        ]);
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
