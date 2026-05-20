<?php

namespace App\Services\Directory;

use App\Jobs\Directory\RefreshSingleLdapGroupJob;
use App\Models\Directory\LdapGroupEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapSingleGroupRefreshDispatcher
{
    public function queue(LdapGroupEntry $groupEntry): array
    {
        try {
            $groupEntry->refresh();

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'refresh_single_ldap_group',
                'type' => 'refresh_single_ldap_group',
                'name' => 'Refresh LDAP Group - '.($groupEntry->cn ?: $groupEntry->ou ?: $groupEntry->id),
                'module' => 'directory.groups',
                'operation_action' => 'refresh_single_ldap_group',
                'action' => 'refresh_single_ldap_group',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapGroupEntry::class,
                'target_key' => (string) $groupEntry->id,
                'target_dn' => $groupEntry->dn,
                'ldap_connection_id' => $groupEntry->ldap_connection_id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'refresh_single_ldap_group',
                    'ldap_group_entry_id' => $groupEntry->id,
                    'ldap_connection_id' => $groupEntry->ldap_connection_id,
                    'target_dn' => $groupEntry->dn,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            RefreshSingleLdapGroupJob::dispatch(
                operationJobId: $operationJob->id,
                ldapGroupEntryId: $groupEntry->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.groups',
                'action' => 'queue_refresh_single_ldap_group',
                'status' => 'success',
                'target_type' => LdapGroupEntry::class,
                'target_key' => (string) $groupEntry->id,
                'target_dn' => $groupEntry->dn,
                'ldap_connection_id' => $groupEntry->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'queue' => 'ldap',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Single LDAP group refresh queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.groups',
                'action' => 'queue_refresh_single_ldap_group',
                'status' => 'failed',
                'target_type' => LdapGroupEntry::class,
                'target_key' => (string) $groupEntry->id,
                'target_dn' => $groupEntry->dn,
                'ldap_connection_id' => $groupEntry->ldap_connection_id,
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'operation_job' => null,
            ];
        }
    }
}
