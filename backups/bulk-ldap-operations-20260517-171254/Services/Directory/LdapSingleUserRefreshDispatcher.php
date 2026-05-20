<?php

namespace App\Services\Directory;

use App\Jobs\Directory\RefreshSingleLdapUserJob;
use App\Models\Directory\LdapUserEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LdapSingleUserRefreshDispatcher
{
    public function queue(LdapUserEntry $userEntry): array
    {
        try {
            $userEntry->refresh();

            $operationJob = app(OperationJobTracker::class)->create([
                'operation_type' => 'refresh_single_ldap_user',
                'type' => 'refresh_single_ldap_user',
                'name' => 'Refresh LDAP User - '.($userEntry->uid ?: $userEntry->cn ?: $userEntry->id),
                'module' => 'directory.users',
                'operation_action' => 'refresh_single_ldap_user',
                'action' => 'refresh_single_ldap_user',
                'status' => 'queued',
                'source' => 'filament',
                'target_type' => LdapUserEntry::class,
                'target_key' => (string) $userEntry->id,
                'target_dn' => $userEntry->dn,
                'ldap_connection_id' => $userEntry->ldap_connection_id,
                'total_items' => 1,
                'processed_items' => 0,
                'success_items' => 0,
                'failed_items' => 0,
                'metadata' => [
                    'source' => 'filament',
                    'action' => 'refresh_single_ldap_user',
                    'ldap_user_entry_id' => $userEntry->id,
                    'ldap_connection_id' => $userEntry->ldap_connection_id,
                    'target_dn' => $userEntry->dn,
                    'queue' => 'ldap',
                    'actor_user_id' => Auth::id(),
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            RefreshSingleLdapUserJob::dispatch(
                operationJobId: $operationJob->id,
                ldapUserEntryId: $userEntry->id,
            );

            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'queue_refresh_single_ldap_user',
                'status' => 'success',
                'target_type' => LdapUserEntry::class,
                'target_key' => (string) $userEntry->id,
                'target_dn' => $userEntry->dn,
                'ldap_connection_id' => $userEntry->ldap_connection_id,
                'operation_job_id' => $operationJob->id,
                'request_payload' => [
                    'queue' => 'ldap',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            return [
                'ok' => true,
                'message' => 'Single LDAP user refresh queued.',
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            app(AuditLogger::class)->log([
                'module' => 'directory.users',
                'action' => 'queue_refresh_single_ldap_user',
                'status' => 'failed',
                'target_type' => LdapUserEntry::class,
                'target_key' => (string) $userEntry->id,
                'target_dn' => $userEntry->dn,
                'ldap_connection_id' => $userEntry->ldap_connection_id,
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
