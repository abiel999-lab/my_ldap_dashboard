<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapGroupEntry;
use App\Models\Directory\LdapUnitEntry;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

class LdapUnitSyncService
{
    public function sync(): array
    {
        $startedAt = microtime(true);
        $actor = Auth::user();

        $execution = CommandExecution::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'module' => 'directory.units',
            'command_type' => 'ldap_unit_sync_from_group_cache',
            'status' => 'running',
            'command' => 'internal:sync-ldap-units-from-ldap-group-cache --read-only',
            'working_directory' => base_path(),
            'environment_context' => RedactsSensitiveData::redact([
                'source' => 'ldap_group_entries',
                'read_only' => true,
                'ldap_will_change' => false,
            ]),
            'safe_mode' => true,
            'preview_mode' => true,
            'destructive' => false,
            'started_at' => now(),
        ]);

        try {
            $groups = $this->unitSourceGroups();

            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($groups as $group) {
                $seenDns[] = $group->dn;

                $normalized = $this->normalizeUnitFromGroup($group, $groups);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapUnitEntry::query()->firstOrNew([
                    'ldap_connection_id' => $group->ldap_connection_id,
                    'dn' => $group->dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
                    'ldap_group_entry_id' => $group->id,
                    'parent_dn' => $normalized['parent_dn'],
                    'entry_uuid' => $group->entry_uuid,
                    'ou' => $normalized['ou'],
                    'unit_key' => $normalized['unit_key'],
                    'unit_name' => $normalized['unit_name'],
                    'unit_type' => $normalized['unit_type'],
                    'tree_level' => $normalized['tree_level'],
                    'direct_child_count' => $normalized['direct_child_count'],
                    'user_count' => $normalized['user_count'],
                    'group_count' => $normalized['group_count'],
                    'source' => 'ldap_ou',
                    'status' => 'active',
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'child_unit_dns' => $normalized['child_unit_dns'],
                    'metadata' => $normalized['metadata'],
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapUnitEntry::query()
                ->where('source', 'ldap_ou')
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP units synced from group cache. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
                'stderr' => null,
                'exit_code' => 0,
                'error_message' => null,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution, 'success', [
                'seen' => count($seenDns),
                'created' => $created,
                'updated' => $updated,
                'source' => 'ldap_group_entries',
                'ldap_was_changed' => false,
            ]);

            return [
                'ok' => true,
                'message' => 'LDAP units synced from group cache successfully.',
                'created' => $created,
                'updated' => $updated,
                'seen' => count($seenDns),
                'command_execution_id' => $execution->id,
            ];
        } catch (Throwable $exception) {
            $execution->forceFill([
                'status' => 'failed',
                'stdout' => null,
                'stderr' => $exception->getMessage(),
                'exit_code' => 1,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedAt),
            ])->save();

            $this->audit($execution, 'failed', [
                'seen' => 0,
                'created' => 0,
                'updated' => 0,
                'source' => 'ldap_group_entries',
                'ldap_was_changed' => false,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'created' => 0,
                'updated' => 0,
                'seen' => 0,
                'command_execution_id' => $execution->id,
            ];
        }
    }

    private function unitSourceGroups(): Collection
    {
        return LdapGroupEntry::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query
                    ->where('group_type', 'organizational_unit')
                    ->orWhereNotNull('ou')
                    ->orWhere('dn', 'ILIKE', 'ou=%');
            })
            ->orderBy('ldap_connection_id')
            ->orderBy('dn')
            ->get();
    }

    private function normalizeUnitFromGroup(LdapGroupEntry $group, Collection $allUnits): array
    {
        $ou = $group->ou ?: $this->extractRdnValue($group->dn);
        $parentDn = $this->parentDn($group->dn);
        $childUnitDns = $this->childUnitDns($group, $allUnits);

        return [
            'parent_dn' => $parentDn,
            'ou' => $ou,
            'unit_key' => $this->normalizeUnitKey($ou),
            'unit_name' => $this->humanizeUnitName($ou),
            'unit_type' => $this->detectUnitType($ou, $group->dn),
            'tree_level' => $this->treeLevel($group->dn),
            'direct_child_count' => count($childUnitDns),
            'user_count' => $this->countUsersUnderDn($group),
            'group_count' => $this->countGroupsUnderDn($group),
            'object_classes' => $group->object_classes ?? [],
            'attributes' => $group->attributes ?? [],
            'child_unit_dns' => $childUnitDns,
            'metadata' => [
                'source_group_id' => $group->id,
                'source_group_type' => $group->group_type,
                'source_group_dn' => $group->dn,
                'ldap_was_changed' => false,
                'detected_from' => $this->detectedFrom($group),
            ],
        ];
    }

    private function childUnitDns(LdapGroupEntry $group, Collection $allUnits): array
    {
        $parent = $this->normalizeDn($group->dn);

        return $allUnits
            ->filter(function (LdapGroupEntry $candidate) use ($parent): bool {
                $candidateParent = $this->normalizeDn($this->parentDn($candidate->dn));

                return $candidateParent !== '' && $candidateParent === $parent;
            })
            ->pluck('dn')
            ->filter()
            ->values()
            ->all();
    }

    private function countUsersUnderDn(LdapGroupEntry $group): int
    {
        $unitDn = $this->normalizeDn($group->dn);

        if ($unitDn === '') {
            return 0;
        }

        return LdapUserEntry::query()
            ->where('ldap_connection_id', $group->ldap_connection_id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (LdapUserEntry $user): bool => str_ends_with($this->normalizeDn($user->dn), $unitDn))
            ->count();
    }

    private function countGroupsUnderDn(LdapGroupEntry $group): int
    {
        $unitDn = $this->normalizeDn($group->dn);

        if ($unitDn === '') {
            return 0;
        }

        return LdapGroupEntry::query()
            ->where('ldap_connection_id', $group->ldap_connection_id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (LdapGroupEntry $candidate): bool => $candidate->id !== $group->id && str_ends_with($this->normalizeDn($candidate->dn), $unitDn))
            ->count();
    }

    private function detectUnitType(?string $ou, ?string $dn): string
    {
        $haystack = mb_strtolower(trim((string) $ou.' '.(string) $dn));

        return match (true) {
            str_contains($haystack, 'people') => 'people_container',
            str_contains($haystack, 'group') => 'groups_container',
            str_contains($haystack, 'app') => 'applications_container',
            str_contains($haystack, 'role') => 'roles_container',
            str_contains($haystack, 'unit') => 'units_container',
            str_contains($haystack, 'device') => 'devices_container',
            str_contains($haystack, 'service') => 'services_container',
            str_contains($haystack, 'policy') => 'policies_container',
            str_contains($haystack, 'student') => 'student_unit',
            str_contains($haystack, 'staff') => 'staff_unit',
            str_contains($haystack, 'alumni') => 'alumni_unit',
            str_contains($haystack, 'external') => 'external_unit',
            default => 'organizational_unit',
        };
    }

    private function detectedFrom(LdapGroupEntry $group): array
    {
        return [
            'group_type_is_organizational_unit' => $group->group_type === 'organizational_unit',
            'ou_attribute_exists' => filled($group->ou),
            'dn_starts_with_ou' => str_starts_with(mb_strtolower((string) $group->dn), 'ou='),
            'object_classes' => $group->object_classes ?? [],
        ];
    }

    private function normalizeUnitKey(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'unit';
        $value = trim($value, '_');

        return $value === '' ? 'unit' : $value;
    }

    private function humanizeUnitName(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'LDAP Unit';
        }

        return str($value)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function extractRdnValue(?string $dn): ?string
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return null;
        }

        $first = explode(',', $dn)[0] ?? $dn;

        if (! str_contains($first, '=')) {
            return $first;
        }

        $parts = explode('=', $first, 2);

        return $parts[1] ?? $first;
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

        return substr_count(mb_strtolower($dn), 'ou=');
    }

    private function normalizeDn(?string $dn): string
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', '', $dn) ?? $dn);
    }

    private function audit(CommandExecution $execution, string $status, array $summary): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.units',
            'action' => 'sync_ldap_units_from_group_cache',
            'status' => $status,
            'target_type' => LdapUnitEntry::class,
            'target_key' => 'ldap_group_cache',
            'request_payload' => [
                'source' => 'ldap_group_entries',
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

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
