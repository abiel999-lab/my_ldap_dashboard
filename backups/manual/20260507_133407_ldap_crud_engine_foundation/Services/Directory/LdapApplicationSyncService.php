<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapApplicationEntry;
use App\Models\Directory\LdapGroupEntry;
use App\Models\Directory\LdapRoleEntry;
use App\Models\Operations\CommandExecution;
use App\Services\Audit\AuditLogger;
use App\Support\Security\RedactsSensitiveData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

class LdapApplicationSyncService
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
            'module' => 'directory.applications',
            'command_type' => 'ldap_application_sync_from_group_cache',
            'status' => 'running',
            'command' => 'internal:sync-ldap-applications-from-ldap-group-cache --read-only',
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
            $groups = $this->applicationSourceGroups();

            $created = 0;
            $updated = 0;
            $seenDns = [];

            foreach ($groups as $group) {
                $seenDns[] = $group->dn;

                $normalized = $this->normalizeApplicationFromGroup($group);
                $sourceHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));

                $model = LdapApplicationEntry::query()->firstOrNew([
                    'ldap_connection_id' => $group->ldap_connection_id,
                    'dn' => $group->dn,
                ]);

                $wasRecentlyCreated = ! $model->exists;

                $model->forceFill([
                    'ldap_group_entry_id' => $group->id,
                    'entry_uuid' => $group->entry_uuid,
                    'app_key' => $normalized['app_key'],
                    'app_name' => $normalized['app_name'],
                    'cn' => $normalized['cn'],
                    'application_type' => $normalized['application_type'],
                    'integration_type' => $normalized['integration_type'],
                    'environment' => $normalized['environment'],
                    'description' => $normalized['description'],
                    'allowed_group_count' => $normalized['allowed_group_count'],
                    'required_role_count' => $normalized['required_role_count'],
                    'resolved_user_count' => $normalized['resolved_user_count'],
                    'allowed_group_dns' => $normalized['allowed_group_dns'],
                    'required_role_ids' => $normalized['required_role_ids'],
                    'required_role_keys' => $normalized['required_role_keys'],
                    'resolved_user_ids' => $normalized['resolved_user_ids'],
                    'oidc_enabled' => $normalized['oidc_enabled'],
                    'saml_enabled' => $normalized['saml_enabled'],
                    'api_access_enabled' => $normalized['api_access_enabled'],
                    'source' => 'ldap_group',
                    'status' => 'active',
                    'object_classes' => $normalized['object_classes'],
                    'attributes' => $normalized['attributes'],
                    'metadata' => $normalized['metadata'],
                    'source_hash' => $sourceHash,
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            }

            LdapApplicationEntry::query()
                ->where('source', 'ldap_group')
                ->whereNotIn('dn', $seenDns)
                ->update([
                    'status' => 'missing_from_ldap',
                    'last_synced_at' => now(),
                ]);

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP applications synced from group cache. Seen: '.count($seenDns).', Created: '.$created.', Updated: '.$updated.'.',
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
                'message' => 'LDAP applications synced from group cache successfully.',
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

    private function applicationSourceGroups()
    {
        return LdapGroupEntry::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query
                    ->where('group_type', 'app_group')
                    ->orWhere('cn', 'ILIKE', 'app-%')
                    ->orWhere('dn', 'ILIKE', '%ou=apps%')
                    ->orWhere('dn', 'ILIKE', '%cn=app-%');
            })
            ->orderBy('ldap_connection_id')
            ->orderBy('cn')
            ->get();
    }

    private function normalizeApplicationFromGroup(LdapGroupEntry $group): array
    {
        $cn = $group->cn ?: $this->extractRdnValue($group->dn);
        $appKey = $this->normalizeAppKey($cn);
        $relatedRoles = $this->relatedRolesForApplication($group, $appKey);

        $requiredRoleIds = $relatedRoles->pluck('id')->values()->all();
        $requiredRoleKeys = $relatedRoles->pluck('role_key')->filter()->values()->all();

        $resolvedUserIds = collect($group->member_dns ?? [])
            ->merge($relatedRoles->flatMap(fn (LdapRoleEntry $role) => $role->resolved_user_ids ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $integrationType = $this->detectIntegrationType($cn, $group->dn);

        return [
            'app_key' => $appKey,
            'app_name' => $this->humanizeAppName($cn),
            'cn' => $cn,
            'application_type' => $this->detectApplicationType($cn, $group->dn),
            'integration_type' => $integrationType,
            'environment' => $this->detectEnvironment($cn, $group->dn),
            'description' => $group->description,
            'allowed_group_count' => 1,
            'required_role_count' => count($requiredRoleIds),
            'resolved_user_count' => count($resolvedUserIds),
            'allowed_group_dns' => [$group->dn],
            'required_role_ids' => $requiredRoleIds,
            'required_role_keys' => $requiredRoleKeys,
            'resolved_user_ids' => $resolvedUserIds,
            'oidc_enabled' => $integrationType === 'oidc',
            'saml_enabled' => $integrationType === 'saml',
            'api_access_enabled' => str_contains(mb_strtolower((string) $cn.' '.(string) $group->dn), 'api'),
            'object_classes' => $group->object_classes ?? [],
            'attributes' => $group->attributes ?? [],
            'metadata' => [
                'source_group_id' => $group->id,
                'source_group_type' => $group->group_type,
                'source_group_dn' => $group->dn,
                'related_role_count' => count($requiredRoleIds),
                'ldap_was_changed' => false,
                'detected_from' => $this->detectedFrom($group),
            ],
        ];
    }

    private function relatedRolesForApplication(LdapGroupEntry $group, string $appKey)
    {
        $connectionId = $group->ldap_connection_id;
        $plainKey = preg_replace('/^app_/', '', $appKey) ?: $appKey;
        $dashKey = str_replace('_', '-', $appKey);
        $plainDashKey = preg_replace('/^app-/', '', $dashKey) ?: $dashKey;

        return LdapRoleEntry::query()
            ->where('status', 'active')
            ->where('ldap_connection_id', $connectionId)
            ->where(function ($query) use ($appKey, $plainKey, $dashKey, $plainDashKey): void {
                $query
                    ->where('application_key', $appKey)
                    ->orWhere('application_key', $dashKey)
                    ->orWhere('dn', 'ILIKE', '%'.$dashKey.'%')
                    ->orWhere('dn', 'ILIKE', '%'.$plainDashKey.'%')
                    ->orWhere('role_key', 'ILIKE', '%'.$plainKey.'%')
                    ->orWhere('role_key', 'ILIKE', '%'.$plainDashKey.'%');
            })
            ->orderBy('role_type')
            ->orderBy('role_key')
            ->get();
    }

    private function detectApplicationType(?string $cn, ?string $dn): string
    {
        $haystack = mb_strtolower(trim((string) $cn.' '.(string) $dn));

        return match (true) {
            str_contains($haystack, 'wifi') || str_contains($haystack, 'dot1x') => 'network_access_app',
            str_contains($haystack, 'mobile') => 'mobile_app',
            str_contains($haystack, 'web') => 'web_app',
            str_contains($haystack, 'api') => 'api_app',
            default => 'ldap_app_group',
        };
    }

    private function detectIntegrationType(?string $cn, ?string $dn): ?string
    {
        $haystack = mb_strtolower(trim((string) $cn.' '.(string) $dn));

        return match (true) {
            str_contains($haystack, 'saml') => 'saml',
            str_contains($haystack, 'oidc') || str_contains($haystack, 'web') || str_contains($haystack, 'mobile') => 'oidc',
            str_contains($haystack, 'radius') || str_contains($haystack, 'wifi') || str_contains($haystack, 'dot1x') => 'radius',
            str_contains($haystack, 'api') => 'api',
            default => null,
        };
    }

    private function detectEnvironment(?string $cn, ?string $dn): ?string
    {
        $haystack = mb_strtolower(trim((string) $cn.' '.(string) $dn));

        return match (true) {
            str_contains($haystack, 'prod') || str_contains($haystack, 'production') => 'production',
            str_contains($haystack, 'stage') || str_contains($haystack, 'staging') => 'staging',
            str_contains($haystack, 'test') || str_contains($haystack, 'testing') => 'testing',
            str_contains($haystack, 'dev') || str_contains($haystack, 'development') => 'development',
            default => null,
        };
    }

    private function detectedFrom(LdapGroupEntry $group): array
    {
        return [
            'group_type_is_app_group' => $group->group_type === 'app_group',
            'cn_starts_with_app' => str_starts_with(mb_strtolower((string) $group->cn), 'app-'),
            'dn_contains_apps_ou' => str_contains(mb_strtolower((string) $group->dn), 'ou=apps'),
            'dn_contains_app_cn' => str_contains(mb_strtolower((string) $group->dn), 'cn=app-'),
        ];
    }

    private function normalizeAppKey(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: 'app';
        $value = trim($value, '_');

        return $value === '' ? 'app' : $value;
    }

    private function humanizeAppName(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'LDAP Application';
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
            'module' => 'directory.applications',
            'action' => 'sync_ldap_applications_from_group_cache',
            'status' => $status,
            'target_type' => LdapApplicationEntry::class,
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
