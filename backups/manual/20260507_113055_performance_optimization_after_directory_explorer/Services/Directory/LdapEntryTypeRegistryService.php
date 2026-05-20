<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapEntryTypeRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Throwable;

class LdapEntryTypeRegistryService
{
    public function seedDefaults(): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->defaultRules() as $rule) {
            $model = LdapEntryTypeRule::query()->firstOrNew([
                'rule_key' => $rule['rule_key'],
            ]);

            $wasRecentlyCreated = ! $model->exists;

            $model->forceFill($rule)->save();

            $wasRecentlyCreated ? $created++ : $updated++;
        }

        app(AuditLogger::class)->log([
            'module' => 'directory.type_registry',
            'action' => 'seed_default_ldap_entry_type_rules',
            'status' => 'success',
            'target_type' => LdapEntryTypeRule::class,
            'target_key' => 'default_rules',
            'request_payload' => [
                'ldap_was_changed' => false,
                'rule_count' => count($this->defaultRules()),
            ],
            'after_value' => [
                'created' => $created,
                'updated' => $updated,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Default LDAP entry type rules seeded.',
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function classify(?string $dn, array $attributes = []): array
    {
        $dn = trim((string) $dn);

        $objectClasses = collect($attributes['objectClass'] ?? $attributes['objectclass'] ?? [])
            ->when(! is_array($attributes['objectClass'] ?? $attributes['objectclass'] ?? []), fn (Collection $collection) => collect([$attributes['objectClass'] ?? $attributes['objectclass'] ?? null]))
            ->map(fn ($item): string => mb_strtolower(trim((string) $item)))
            ->filter()
            ->values()
            ->all();

        $rules = LdapEntryTypeRule::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($rule, $dn, $objectClasses)) {
                return [
                    'matched' => true,
                    'rule_id' => $rule->id,
                    'rule_key' => $rule->rule_key,
                    'entry_type' => $rule->entry_type,
                    'entry_category' => $rule->entry_category,
                    'identifier_attribute' => $rule->identifier_attribute,
                    'display_attribute' => $rule->display_attribute,
                    'email_attribute' => $rule->email_attribute,
                    'uuid_attribute' => $rule->uuid_attribute,
                    'membership_attribute' => $rule->membership_attribute,
                    'badge_color' => $rule->badge_color,
                    'filament_icon' => $rule->filament_icon,
                ];
            }
        }

        return [
            'matched' => false,
            'rule_id' => null,
            'rule_key' => null,
            'entry_type' => 'generic_entry',
            'entry_category' => 'generic',
            'identifier_attribute' => 'dn',
            'display_attribute' => 'dn',
            'email_attribute' => null,
            'uuid_attribute' => null,
            'membership_attribute' => null,
            'badge_color' => 'gray',
            'filament_icon' => 'heroicon-o-document',
        ];
    }

    public function classifyText(?string $dn, array $attributes = []): string
    {
        $result = $this->classify($dn, $attributes);

        return collect($result)
            ->map(fn ($value, $key): string => $key.': '.(is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? 'N/A')))
            ->implode(PHP_EOL);
    }

    private function matches(LdapEntryTypeRule $rule, string $dn, array $objectClasses): bool
    {
        $normalizedDn = $this->normalizeDn($dn);

        $requiredClasses = collect($rule->required_object_classes ?? [])
            ->map(fn ($item): string => mb_strtolower(trim((string) $item)))
            ->filter()
            ->values()
            ->all();

        foreach ($requiredClasses as $requiredClass) {
            if (! in_array($requiredClass, $objectClasses, true)) {
                return false;
            }
        }

        $dnStartsWithPatterns = collect($rule->dn_starts_with_patterns ?? [])
            ->map(fn ($item): string => $this->normalizeDn($item))
            ->filter()
            ->values();

        if ($dnStartsWithPatterns->isNotEmpty()) {
            $matchedStartsWith = $dnStartsWithPatterns->contains(fn (string $pattern): bool => str_starts_with($normalizedDn, $pattern));

            if (! $matchedStartsWith) {
                return false;
            }
        }

        $dnContainsPatterns = collect($rule->dn_contains_patterns ?? [])
            ->map(fn ($item): string => $this->normalizeDn($item))
            ->filter()
            ->values();

        if ($dnContainsPatterns->isNotEmpty()) {
            $matchedContains = $dnContainsPatterns->contains(fn (string $pattern): bool => str_contains($normalizedDn, $pattern));

            if (! $matchedContains) {
                return false;
            }
        }

        $rdnAttributes = collect($rule->rdn_attributes ?? [])
            ->map(fn ($item): string => mb_strtolower(trim((string) $item)))
            ->filter()
            ->values();

        if ($rdnAttributes->isNotEmpty()) {
            $rdn = mb_strtolower(explode('=', explode(',', $dn)[0] ?? '')[0] ?? '');

            if (! $rdnAttributes->contains($rdn)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeDn(?string $dn): string
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', '', $dn) ?? $dn);
    }

    private function defaultRules(): array
    {
        return [
            [
                'rule_key' => 'user_inetorgperson',
                'name' => 'LDAP User - inetOrgPerson',
                'entry_type' => 'user',
                'entry_category' => 'identity',
                'required_object_classes' => ['inetOrgPerson'],
                'optional_object_classes' => ['person', 'organizationalPerson', 'top'],
                'dn_contains_patterns' => [],
                'dn_starts_with_patterns' => ['uid='],
                'rdn_attributes' => ['uid'],
                'identifier_attribute' => 'uid',
                'display_attribute' => 'cn',
                'email_attribute' => 'mail',
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => 'memberOf',
                'filament_icon' => 'heroicon-o-user',
                'badge_color' => 'info',
                'priority' => 10,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect normal LDAP users that use inetOrgPerson and uid RDN.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'group_groupofnames',
                'name' => 'LDAP Group - groupOfNames',
                'entry_type' => 'group',
                'entry_category' => 'authorization',
                'required_object_classes' => ['groupOfNames'],
                'optional_object_classes' => ['top'],
                'dn_contains_patterns' => [],
                'dn_starts_with_patterns' => ['cn='],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => 'member',
                'filament_icon' => 'heroicon-o-user-group',
                'badge_color' => 'success',
                'priority' => 20,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect LDAP groups using groupOfNames.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'role_group',
                'name' => 'LDAP Role Group',
                'entry_type' => 'role',
                'entry_category' => 'authorization',
                'required_object_classes' => ['groupOfNames'],
                'optional_object_classes' => ['top'],
                'dn_contains_patterns' => ['role', 'ou=roles'],
                'dn_starts_with_patterns' => ['cn='],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => 'member',
                'filament_icon' => 'heroicon-o-key',
                'badge_color' => 'warning',
                'priority' => 15,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect LDAP role groups from role naming or ou=roles.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'application_group',
                'name' => 'LDAP Application Group',
                'entry_type' => 'application',
                'entry_category' => 'application_access',
                'required_object_classes' => ['groupOfNames'],
                'optional_object_classes' => ['top'],
                'dn_contains_patterns' => ['ou=apps', 'cn=app-'],
                'dn_starts_with_patterns' => ['cn=app-'],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => 'member',
                'filament_icon' => 'heroicon-o-squares-2x2',
                'badge_color' => 'primary',
                'priority' => 12,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect application access groups such as app-web, app-mobile, app-wifi-dot1x.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'organizational_unit',
                'name' => 'LDAP Organizational Unit',
                'entry_type' => 'unit_ou',
                'entry_category' => 'structure',
                'required_object_classes' => ['organizationalUnit'],
                'optional_object_classes' => ['top'],
                'dn_contains_patterns' => [],
                'dn_starts_with_patterns' => ['ou='],
                'rdn_attributes' => ['ou'],
                'identifier_attribute' => 'ou',
                'display_attribute' => 'ou',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => null,
                'filament_icon' => 'heroicon-o-building-office-2',
                'badge_color' => 'gray',
                'priority' => 30,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect LDAP organizational units.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'device_entry',
                'name' => 'LDAP Device Entry',
                'entry_type' => 'device',
                'entry_category' => 'asset',
                'required_object_classes' => [],
                'optional_object_classes' => ['device', 'ipHost', 'ieee802Device'],
                'dn_contains_patterns' => ['ou=devices'],
                'dn_starts_with_patterns' => ['cn='],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => null,
                'filament_icon' => 'heroicon-o-computer-desktop',
                'badge_color' => 'danger',
                'priority' => 50,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect device entries under ou=devices.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'service_account',
                'name' => 'LDAP Service Account',
                'entry_type' => 'service_account',
                'entry_category' => 'service',
                'required_object_classes' => [],
                'optional_object_classes' => ['simpleSecurityObject', 'organizationalRole'],
                'dn_contains_patterns' => ['ou=services', 'cn=readonly', 'service'],
                'dn_starts_with_patterns' => ['cn='],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => null,
                'filament_icon' => 'heroicon-o-server',
                'badge_color' => 'gray',
                'priority' => 55,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect service or bind accounts.',
                'metadata' => ['ldap_was_changed' => false],
            ],
            [
                'rule_key' => 'policy_entry',
                'name' => 'LDAP Policy Entry',
                'entry_type' => 'policy',
                'entry_category' => 'governance',
                'required_object_classes' => [],
                'optional_object_classes' => ['pwdPolicy', 'device', 'top'],
                'dn_contains_patterns' => ['ou=policies', 'policy'],
                'dn_starts_with_patterns' => ['cn='],
                'rdn_attributes' => ['cn'],
                'identifier_attribute' => 'cn',
                'display_attribute' => 'cn',
                'email_attribute' => null,
                'uuid_attribute' => 'entryUUID',
                'membership_attribute' => null,
                'filament_icon' => 'heroicon-o-shield-check',
                'badge_color' => 'warning',
                'priority' => 60,
                'is_enabled' => true,
                'is_system' => true,
                'description' => 'Detect policy entries.',
                'metadata' => ['ldap_was_changed' => false],
            ],
        ];
    }
}
