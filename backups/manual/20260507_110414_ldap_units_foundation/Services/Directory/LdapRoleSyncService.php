<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapGroupEntry;
use App\Models\Directory\LdapRoleEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

class LdapRoleSyncService
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
            'module' => 'directory.roles',
            'command_type' => 'ldap_role_sync_from_group_cache',
            'status' => 'running',
            'command' => 'internal:sync-ldap-roles-from-ldap-group-cache --read-only',
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
            $groups = $this->roleSourceGroups();

            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($groups as $group) {
                $seenDns[] = $group->dn;

                $normalized = $this->normalizeRoleFromGroup($group);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapRoleEntry::query()->firstOrNew([
                    'ldap_connection_id' => $group->ldap_connection_id,
                    'dn' => $group->dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
                    'ldap_group_entry_id' => $group->id,
                    'entry_uuid' => $group->entry_uuid,
                    'cn' => $normalized['cn'],
                    'role_key' => $normalized['role_key'],
                    'role_name' => $normalized['role_name'],
                    'role_type' => $normalized['role_type'],
                    'role_scope' => $normalized['role_scope'],
                    'application_key' => $normalized['application_key'],
                    'description' => $normalized['description'],
                    'member_count' => $normalized['member_count'],
                    'resolved_user_count' => $normalized['resolved_user_count'],
                    'source' => 'ldap_group',
                    'status' => 'active',
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'member_dns' => $normalized['member_dns'],
                    'member_uids' => $normalized['member_uids'],
                    'resolved_user_ids' => $normalized['resolved_user_ids'],
                    'metadata' => $normalized['metadata'],
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapRoleEntry::query()
                ->where('source', 'ldap_group')
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP roles synced from group cache. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
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
                'message' => 'LDAP roles synced from group cache successfully.',
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

    private function roleSourceGroups()
    {
        return LdapGroupEntry::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query
                    ->where('group_type', 'role_group')
                    ->orWhere('cn', 'ILIKE', '%role%')
                    ->orWhere('dn', 'ILIKE', '%ou=roles%')
                    ->orWhere('dn', 'ILIKE', '%role%');
            })
            ->orderBy('ldap_connection_id')
            ->orderBy('cn')
            ->get();
    }

    private function normalizeRoleFromGroup(LdapGroupEntry $group): array
    {
        $cn = $group->cn ?: $this->extractRdnValue($group->dn);
        $roleKey = $this->normalizeRoleKey($cn);
        $roleType = $this->detectRoleType($cn, $group->dn);
        $roleScope = $this->detectRoleScope($cn, $group->dn);
        $applicationKey = $this->detectApplicationKey($group->dn);

        $resolvedUsers = app(LdapMembershipResolver::class)->usersForGroup($group);
        $resolvedUserIds = $resolvedUsers->pluck('id')->values()->all();

        return [
            'cn' => $cn,
            'role_key' => $roleKey,
            'role_name' => $this->humanizeRoleName($cn),
            'role_type' => $roleType,
            'role_scope' => $roleScope,
            'application_key' => $applicationKey,
            'description' => $group->description,
            'member_count' => (int) $group->member_count,
            'resolved_user_count' => count($resolvedUserIds),
            'object_classes' => $group->object_classes ?? [],
            'attributes' => $group->attributes ?? [],
            'member_dns' => $group->member_dns ?? [],
            'member_uids' => $group->member_uids ?? [],
            'resolved_user_ids' => $resolvedUserIds,
            'metadata' => [
                'source_group_id' => $group->id,
                'source_group_type' => $group->group_type,
                'source_group_dn' => $group->dn,
                'detected_from' => $this->detectedFrom($group),
                'ldap_was_changed' => false,
            ],
        ];
    }

    private function detectRoleType(?string $cn, ?string $dn): string
    {
        $haystack = mb_strtolower(trim((string) $cn.' '.(string) $dn));

        return match (true) {
            str_contains($haystack, 'admin') => 'admin_role',
            str_contains($haystack, 'student') => 'student_role',
            str_contains($haystack, 'staff') => 'staff_role',
            str_contains($haystack, 'alumni') => 'alumni_role',
            str_contains($haystack, 'external') => 'external_role',
            str_contains($haystack, 'app') => 'app_role',
            default => 'user_role',
        };
    }

    private function detectRoleScope(?string $cn, ?string $dn): string
    {
        $haystack = mb_strtolower(trim((string) $cn.' '.(string) $dn));

        if (str_contains($haystack, 'app-') || str_contains($haystack, 'cn=app-') || str_contains($haystack, 'ou=apps')) {
            return 'application';
        }

        if (str_contains($haystack, 'admin')) {
            return 'administration';
        }

        if (str_contains($haystack, 'student') || str_contains($haystack, 'staff') || str_contains($haystack, 'alumni') || str_contains($haystack, 'external')) {
            return 'identity';
        }

        return 'directory';
    }

    private function detectApplicationKey(?string $dn): ?string
    {
        $dn = (string) $dn;

        if (! preg_match('/cn=(app-[^,]+)/i', $dn, $matches)) {
            return null;
        }

        return mb_strtolower($matches[1]);
    }

    private function detectedFrom(LdapGroupEntry $group): array
    {
        return [
            'group_type_is_role_group' => $group->group_type === 'role_group',
            'cn_contains_role' => str_contains(mb_strtolower((string) $group->cn), 'role'),
            'dn_contains_roles_ou' => str_contains(mb_strtolower((string) $group->dn), 'ou=roles'),
            'dn_contains_role' => str_contains(mb_strtolower((string) $group->dn), 'role'),
        ];
    }

    private function normalizeRoleKey(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'role';
        $value = trim($value, '_');

        return $value === '' ? 'role' : $value;
    }

    private function humanizeRoleName(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'LDAP Role';
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

        if (! str_contains($dn, '=')) {
            return $dn;
        }

        $first = explode(',', $dn)[0] ?? $dn;
        $parts = explode('=', $first, 2);

        return $parts[1] ?? $dn;
    }

    private function audit(CommandExecution $execution, string $status, array $summary): void
    {
        app(AuditLogger::class)->log([
            'module' => 'directory.roles',
            'action' => 'sync_ldap_roles_from_group_cache',
            'status' => $status,
            'target_type' => LdapRoleEntry::class,
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
