<?php

namespace App\Services\Radius;

use App\Models\Directory\LdapGroupEntry;
use App\Models\Directory\LdapUserEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class WifiReadinessSyncService
{
    public const WIFI_GROUP_CN = 'app-wifi-dot1x';

    /**
     * This service does not modify LDAP.
     * It verifies the current PostgreSQL mirror against WiFi/RADIUS readiness rules.
     * The actual LDAP source sync remains handled by existing LDAP sync jobs/services.
     */
    public function verifyCurrentMirror(): array
    {
        $baseQuery = $this->effectiveUserQuery();

        $totalUsers = (clone $baseQuery)->count();

        $stats = [
            'total_users' => $totalUsers,
            'ready' => 0,
            'need_password_sync' => 0,
            'need_vlan' => 0,
            'need_wifi_group' => 0,
            'need_samba_repair' => 0,
            'unknown' => 0,

            'has_sambaSamAccount' => 0,
            'has_sambaSID' => 0,
            'has_sambaAcctFlags' => 0,
            'has_sambaNTPassword' => 0,
            'has_userPassword' => 0,
            'has_petraVlan' => 0,
            'wifi_group_members_in_db' => 0,
        ];

        $wifiMembers = $this->loadWifiMemberDnsFromDb();
        $stats['wifi_group_members_in_db'] = count($wifiMembers);

        $samples = [
            'ready' => [],
            'need_password_sync' => [],
            'need_vlan' => [],
            'need_wifi_group' => [],
            'need_samba_repair' => [],
            'unknown' => [],
        ];

        (clone $baseQuery)
            ->select(['id', 'dn', 'uid', 'cn', 'mail', 'attributes', 'last_synced_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(250, function ($users) use (&$stats, &$samples, $wifiMembers): void {
                foreach ($users as $user) {
                    $dn = (string) ($user->dn ?? '');
                    $normalizedDn = $this->normalizeDn($dn);

                    $hasSambaAccount = $this->hasObjectClass($user, 'sambaSamAccount');
                    $hasSambaSid = $this->hasAttr($user, 'sambaSID');
                    $hasSambaFlags = $this->hasAttr($user, 'sambaAcctFlags');
                    $hasNtHash = $this->hasAttr($user, 'sambaNTPassword');
                    $hasUserPassword = $this->hasAttr($user, 'userPassword');
                    $hasVlan = $this->hasAttr($user, 'petraVlan');
                    $inWifiGroup = $normalizedDn !== '' && isset($wifiMembers[$normalizedDn]);

                    $stats['has_sambaSamAccount'] += $hasSambaAccount ? 1 : 0;
                    $stats['has_sambaSID'] += $hasSambaSid ? 1 : 0;
                    $stats['has_sambaAcctFlags'] += $hasSambaFlags ? 1 : 0;
                    $stats['has_sambaNTPassword'] += $hasNtHash ? 1 : 0;
                    $stats['has_userPassword'] += $hasUserPassword ? 1 : 0;
                    $stats['has_petraVlan'] += $hasVlan ? 1 : 0;

                    $status = $this->statusFromBooleans(
                        hasSambaAccount: $hasSambaAccount,
                        hasSambaSid: $hasSambaSid,
                        hasSambaFlags: $hasSambaFlags,
                        hasNtHash: $hasNtHash,
                        hasUserPassword: $hasUserPassword,
                        hasVlan: $hasVlan,
                        inWifiGroup: $inWifiGroup,
                    );

                    $key = match ($status) {
                        'READY' => 'ready',
                        'NEED PASSWORD SYNC' => 'need_password_sync',
                        'NEED VLAN' => 'need_vlan',
                        'NEED WIFI GROUP' => 'need_wifi_group',
                        'NEED SAMBA REPAIR' => 'need_samba_repair',
                        default => 'unknown',
                    };

                    $stats[$key]++;

                    if (count($samples[$key]) < 8) {
                        $samples[$key][] = [
                            'id' => $user->id,
                            'dn' => $dn,
                            'uid' => $this->firstAttr($user, 'uid') ?: $user->uid,
                            'mail' => $this->firstAttr($user, 'mail') ?: $user->mail,
                            'status' => $status,
                        ];
                    }
                }
            });

        $verified = $stats['total_users']
            === ($stats['ready']
                + $stats['need_password_sync']
                + $stats['need_vlan']
                + $stats['need_wifi_group']
                + $stats['need_samba_repair']
                + $stats['unknown']);

        $summary = [
            'verified' => $verified,
            'source' => 'postgresql_ldap_mirror',
            'wifi_group_cn' => self::WIFI_GROUP_CN,
            'stats' => $stats,
            'samples' => $samples,
            'decision' => $this->decision($stats, $verified),
            'notes' => [
                'This check verifies the current PostgreSQL LDAP mirror used by the UI.',
                'If LDAP was changed outside the app, run LDAP user/group sync first, then run WiFi Readiness sync again.',
                'sambaNTPassword cannot be generated from old userPassword hashes; it is created during password change/reset flow.',
            ],
            'generated_at' => now()->toDateTimeString(),
        ];

        return $summary;
    }

    private function decision(array $stats, bool $verified): string
    {
        if (! $verified) {
            return 'PARTIAL_MIRROR_COUNT_MISMATCH';
        }

        if (($stats['need_samba_repair'] ?? 0) > 0) {
            return 'PARTIAL_NEED_SAMBA_REPAIR';
        }

        if (($stats['need_wifi_group'] ?? 0) > 0) {
            return 'PARTIAL_NEED_WIFI_GROUP';
        }

        if (($stats['need_vlan'] ?? 0) > 0) {
            return 'PARTIAL_NEED_VLAN';
        }

        if (($stats['need_password_sync'] ?? 0) > 0) {
            return 'PARTIAL_NEED_PASSWORD_SYNC';
        }

        return 'READY';
    }

    private function statusFromBooleans(
        bool $hasSambaAccount,
        bool $hasSambaSid,
        bool $hasSambaFlags,
        bool $hasNtHash,
        bool $hasUserPassword,
        bool $hasVlan,
        bool $inWifiGroup,
    ): string {
        if (! $hasSambaAccount || ! $hasSambaSid || ! $hasSambaFlags) {
            return 'NEED SAMBA REPAIR';
        }

        if (! $inWifiGroup) {
            return 'NEED WIFI GROUP';
        }

        if (! $hasVlan) {
            return 'NEED VLAN';
        }

        if (! $hasUserPassword || ! $hasNtHash) {
            return 'NEED PASSWORD SYNC';
        }

        return 'READY';
    }

    private function effectiveUserQuery()
    {
        return LdapUserEntry::query()
            ->where('ldap_connection_id', 2)
            ->where('dn', 'like', '%ou=people,dc=petra,dc=ac,dc=id')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', [
                        'missing_from_ldap',
                        'deleted_from_ldap',
                        'missing',
                        'deleted',
                    ]);
            });
    }

    private function loadWifiMemberDnsFromDb(): array
    {
        $members = [];

        $group = LdapGroupEntry::query()
            ->where(function ($query): void {
                $query->where('cn', self::WIFI_GROUP_CN)
                    ->orWhere('dn', 'like', 'cn=' . self::WIFI_GROUP_CN . ',%')
                    ->orWhere('attributes', 'like', '%' . self::WIFI_GROUP_CN . '%');
            })
            ->orderByDesc('updated_at')
            ->first();

        if (! $group) {
            return [];
        }

        foreach ($this->attrValues($group, 'member') as $memberDn) {
            $normalized = $this->normalizeDn($memberDn);

            if ($normalized !== '') {
                $members[$normalized] = true;
            }
        }

        return $members;
    }

    private function hasObjectClass($record, string $class): bool
    {
        foreach ($this->attrValues($record, 'objectClass') as $value) {
            if (strcasecmp($value, $class) === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasAttr($record, string $name): bool
    {
        return $this->attrValues($record, $name) !== [];
    }

    private function firstAttr($record, string $name): ?string
    {
        $values = $this->attrValues($record, $name);

        return $values[0] ?? null;
    }

    private function attrValues($record, string $name): array
    {
        $attributes = $record->attributes ?? [];

        if (is_string($attributes)) {
            $decoded = json_decode($attributes, true);
            $attributes = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($attributes)) {
            return [];
        }

        $value = null;

        foreach ($attributes as $key => $candidate) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = $candidate;
                break;
            }
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        }

        return [trim((string) $value)];
    }

    private function normalizeDn(?string $dn): string
    {
        return strtolower(trim((string) $dn));
    }
}
