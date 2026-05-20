<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapGroupEntry;
use App\Models\Directory\LdapUserEntry;
use Illuminate\Support\Collection;

class LdapMembershipResolver
{
    public function groupsForUser(LdapUserEntry $user): Collection
    {
        $user->refresh();

        $userDn = $this->normalizeDn($user->dn);
        $userUid = $this->normalizeValue($user->uid);
        $userGroupDns = collect($user->group_dns ?? [])
            ->map(fn ($dn): string => $this->normalizeDn($dn))
            ->filter()
            ->values();

        return LdapGroupEntry::query()
            ->where('ldap_connection_id', $user->ldap_connection_id)
            ->where('status', 'active')
            ->orderBy('group_type')
            ->orderBy('cn')
            ->get()
            ->filter(function (LdapGroupEntry $group) use ($userDn, $userUid, $userGroupDns): bool {
                $groupDn = $this->normalizeDn($group->dn);

                $memberDns = collect($group->member_dns ?? [])
                    ->map(fn ($dn): string => $this->normalizeDn($dn))
                    ->filter()
                    ->values();

                $memberUids = collect($group->member_uids ?? [])
                    ->map(fn ($uid): string => $this->normalizeValue($uid))
                    ->filter()
                    ->values();

                if ($userDn !== '' && $memberDns->contains($userDn)) {
                    return true;
                }

                if ($userUid !== '' && $memberUids->contains($userUid)) {
                    return true;
                }

                if ($groupDn !== '' && $userGroupDns->contains($groupDn)) {
                    return true;
                }

                return false;
            })
            ->values();
    }

    public function usersForGroup(LdapGroupEntry $group): Collection
    {
        $group->refresh();

        $groupDn = $this->normalizeDn($group->dn);

        $groupMemberDns = collect($group->member_dns ?? [])
            ->map(fn ($dn): string => $this->normalizeDn($dn))
            ->filter()
            ->values();

        $groupMemberUids = collect($group->member_uids ?? [])
            ->map(fn ($uid): string => $this->normalizeValue($uid))
            ->filter()
            ->values();

        return LdapUserEntry::query()
            ->where('ldap_connection_id', $group->ldap_connection_id)
            ->where('status', 'active')
            ->orderBy('uid')
            ->orderBy('cn')
            ->get()
            ->filter(function (LdapUserEntry $user) use ($groupDn, $groupMemberDns, $groupMemberUids): bool {
                $userDn = $this->normalizeDn($user->dn);
                $userUid = $this->normalizeValue($user->uid);

                $userGroupDns = collect($user->group_dns ?? [])
                    ->map(fn ($dn): string => $this->normalizeDn($dn))
                    ->filter()
                    ->values();

                if ($userDn !== '' && $groupMemberDns->contains($userDn)) {
                    return true;
                }

                if ($userUid !== '' && $groupMemberUids->contains($userUid)) {
                    return true;
                }

                if ($groupDn !== '' && $userGroupDns->contains($groupDn)) {
                    return true;
                }

                return false;
            })
            ->values();
    }

    public function groupsForUserText(LdapUserEntry $user): string
    {
        $groups = $this->groupsForUser($user);

        if ($groups->isEmpty()) {
            return 'No resolved group membership from cache.';
        }

        return $groups
            ->map(function (LdapGroupEntry $group): string {
                $label = $group->cn ?: $group->ou ?: 'N/A';
                $type = $group->group_type ?: 'ldap_group';

                return '- '.$label.' ['.$type.']'.PHP_EOL.'  DN: '.$group->dn;
            })
            ->implode(PHP_EOL);
    }

    public function usersForGroupText(LdapGroupEntry $group): string
    {
        $users = $this->usersForGroup($group);

        if ($users->isEmpty()) {
            return 'No resolved user members from cache.';
        }

        return $users
            ->map(function (LdapUserEntry $user): string {
                $label = $user->uid ?: $user->cn ?: $user->mail ?: 'N/A';
                $mail = $user->mail ?: 'N/A';

                return '- '.$label.' <'.$mail.'>'.PHP_EOL.'  DN: '.$user->dn;
            })
            ->implode(PHP_EOL);
    }

    public function groupsForUserCount(LdapUserEntry $user): int
    {
        return $this->groupsForUser($user)->count();
    }

    public function usersForGroupCount(LdapGroupEntry $group): int
    {
        return $this->usersForGroup($group)->count();
    }

    private function normalizeDn(?string $dn): string
    {
        $dn = trim((string) $dn);

        if ($dn === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', '', $dn) ?? $dn);
    }

    private function normalizeValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
