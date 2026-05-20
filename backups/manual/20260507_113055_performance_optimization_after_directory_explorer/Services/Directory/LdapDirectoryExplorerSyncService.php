<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Process\Process;
use Throwable;

class LdapDirectoryExplorerSyncService
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
            'module' => 'directory.explorer',
            'command_type' => 'ldap_directory_explorer_sync',
            'status' => $connection ? 'running' : 'blocked',
            'command' => $connection ? $this->displayCommand($connection) : 'ldapsearch [NO_CONNECTION]',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'ldap_connection_id' => $connection?->id,
                'ldap_connection_name' => $connection?->name,
                'base_dn' => $connection?->base_dn,
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
            $process->setTimeout(240);
            $process->run();

            $stdout = $this->redactString($process->getOutput());
            $stderr = $this->redactString($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => 'LDAP directory explorer sync ldapsearch failed.',
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
                    'message' => 'LDAP directory explorer sync failed.',
                    'seen' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'command_execution_id' => $execution->id,
                ];
            }

            $entries = $this->parseLdifEntries($stdout);
            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($entries as $entry) {
                $dn = $entry['dn'] ?? null;

                if (blank($dn)) {
                    continue;
                }

                $seenDns[] = $dn;

                $normalized = $this->normalizeDirectoryEntry($connection, $entry);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapDirectoryEntry::query()->firstOrNew([
                    'ldap_connection_id' => $connection->id,
                    'dn' => $dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
                    'ldap_entry_type_rule_id' => $normalized['ldap_entry_type_rule_id'],
                    'parent_dn' => $normalized['parent_dn'],
                    'rdn' => $normalized['rdn'],
                    'rdn_attribute' => $normalized['rdn_attribute'],
                    'rdn_value' => $normalized['rdn_value'],
                    'entry_uuid' => $normalized['entry_uuid'],
                    'entry_type' => $normalized['entry_type'],
                    'entry_category' => $normalized['entry_category'],
                    'identifier_attribute' => $normalized['identifier_attribute'],
                    'identifier_value' => $normalized['identifier_value'],
                    'display_attribute' => $normalized['display_attribute'],
                    'display_value' => $normalized['display_value'],
                    'email_attribute' => $normalized['email_attribute'],
                    'email_value' => $normalized['email_value'],
                    'tree_level' => $normalized['tree_level'],
                    'child_count' => $normalized['child_count'],
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'operational_attributes' => $normalized['operational_attributes'],
                    'metadata' => $normalized['metadata'],
                    'source' => 'ldap_search',
                    'status' => 'active',
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapDirectoryEntry::query()
                ->where('ldap_connection_id', $connection->id)
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $this->updateChildCounts($connection);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP directory explorer synced. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($connection, $execution, 'success', [
                'seen' => count($seenDns),
                'created' => $created,
                'updated' => $updated,
                'ldap_was_changed' => false,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP directory explorer synced successfully.',
                'seen' => count($seenDns),
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
            $connection->base_dn,
            '(objectClass=*)',
            '*',
            '+',
        ];
    }

    private function displayCommand(LdapConnection $connection): string
    {
        return 'ldapsearch -LLL -x'
            .' -H ldap://'.$connection->host.':'.$connection->port
            .' -D '.$connection->bind_dn
            .' -w [REDACTED]'
            .' -b '.$connection->base_dn
            .' "(objectClass=*)" "*" "+"';
    }

    private function parseLdifEntries(string $content): array
    {
        $lines = preg_split("/\R/", $content) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $unfolded[] = '';
                continue;
            }

            if (str_starts_with($line, ' ') && $unfolded !== []) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
                continue;
            }

            $unfolded[] = $line;
        }

        $blocks = preg_split("/\n\s*\n/", implode("\n", $unfolded)) ?: [];
        $entries = [];

        foreach ($blocks as $block) {
            $entry = [];

            foreach (preg_split("/\R/", trim($block)) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);

                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                if (isset($entry[$key])) {
                    if (! is_array($entry[$key])) {
                        $entry[$key] = [$entry[$key]];
                    }

                    $entry[$key][] = $value;
                } else {
                    $entry[$key] = $value;
                }
            }

            if ($entry !== []) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function normalizeDirectoryEntry(LdapConnection $connection, array $entry): array
    {
        $dn = (string) ($entry['dn'] ?? '');
        $objectClasses = $this->asArray($entry['objectClass'] ?? []);

        $classification = app(LdapEntryTypeRegistryService::class)->classify($dn, [
            'objectClass' => $objectClasses,
        ]);

        $identifierAttribute = $classification['identifier_attribute'] ?? null;
        $displayAttribute = $classification['display_attribute'] ?? null;
        $emailAttribute = $classification['email_attribute'] ?? null;
        $uuidAttribute = $classification['uuid_attribute'] ?? 'entryUUID';

        [$rdn, $rdnAttribute, $rdnValue] = $this->rdnParts($dn);

        return [
            'ldap_entry_type_rule_id' => $classification['rule_id'] ?? null,
            'parent_dn' => $this->parentDn($dn),
            'rdn' => $rdn,
            'rdn_attribute' => $rdnAttribute,
            'rdn_value' => $rdnValue,
            'entry_uuid' => $this->firstValue($entry[$uuidAttribute] ?? $entry['entryUUID'] ?? null),
            'entry_type' => $classification['entry_type'] ?? 'generic_entry',
            'entry_category' => $classification['entry_category'] ?? 'generic',
            'identifier_attribute' => $identifierAttribute,
            'identifier_value' => $identifierAttribute ? $this->firstValue($entry[$identifierAttribute] ?? null) : null,
            'display_attribute' => $displayAttribute,
            'display_value' => $displayAttribute ? $this->firstValue($entry[$displayAttribute] ?? null) : null,
            'email_attribute' => $emailAttribute,
            'email_value' => $emailAttribute ? $this->firstValue($entry[$emailAttribute] ?? null) : null,
            'tree_level' => $this->treeLevel($dn),
            'child_count' => 0,
            'object_classes' => $objectClasses,
            'attributes' => $entry,
            'operational_attributes' => [
                'entryUUID' => $entry['entryUUID'] ?? null,
                'creatorsName' => $entry['creatorsName'] ?? null,
                'createTimestamp' => $entry['createTimestamp'] ?? null,
                'modifiersName' => $entry['modifiersName'] ?? null,
                'modifyTimestamp' => $entry['modifyTimestamp'] ?? null,
                'structuralObjectClass' => $entry['structuralObjectClass'] ?? null,
                'entryCSN' => $entry['entryCSN'] ?? null,
            ],
            'metadata' => [
                'matched_rule_key' => $classification['rule_key'] ?? null,
                'matched' => $classification['matched'] ?? false,
                'base_dn' => $connection->base_dn,
                'ldap_was_changed' => false,
            ],
        ];
    }

    private function updateChildCounts(LdapConnection $connection): void
    {
        $entries = LdapDirectoryEntry::query()
            ->where('ldap_connection_id', $connection->id)
            ->where('status', 'active')
            ->get(['id', 'dn', 'parent_dn']);

        foreach ($entries as $entry) {
            $count = $entries
                ->filter(fn (LdapDirectoryEntry $candidate): bool => $this->normalizeDn($candidate->parent_dn) === $this->normalizeDn($entry->dn))
                ->count();

            $entry->forceFill(['child_count' => $count])->save();
        }
    }

    private function rdnParts(string $dn): array
    {
        $rdn = explode(',', $dn)[0] ?? $dn;

        if (! str_contains($rdn, '=')) {
            return [$rdn, null, $rdn];
        }

        [$attribute, $value] = explode('=', $rdn, 2);

        return [$rdn, trim($attribute), trim($value)];
    }

    private function parentDn(?string $dn): ?string
    {
        $dn = trim((string) $dn);

        if ($dn === '' || ! str_contains($dn, ',')) {
            return null;
        }

        $parts = explode(',', $dn);
        array_shift($parts);

        $parent = implode(',', $parts);

        return trim($parent) === '' ? null : trim($parent);
    }

    private function treeLevel(?string $dn): int
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return 0;
        }

        return max(substr_count($dn, ','), 0);
    }

    private function normalizeDn(?string $dn): string
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', '', $dn) ?? $dn);
    }

    private function asArray($value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
    }

    private function firstValue($value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function audit(?LdapConnection $connection, CommandExecution $execution, string $status, array $summary): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.explorer',
            'action' => 'sync_ldap_directory_explorer',
            'status' => $status,
            'target_type' => LdapConnection::class,
            'target_key' => (string) ($connection?->id ?? 'N/A'),
            'target_dn' => $connection?->base_dn,
            'ldap_connection_id' => $connection?->id,
            'request_payload' => [
                'base_dn' => $connection?->base_dn,
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
