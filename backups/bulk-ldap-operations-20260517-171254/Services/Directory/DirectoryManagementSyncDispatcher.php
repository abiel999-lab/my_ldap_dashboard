<?php

namespace App\Services\Directory;

use App\Jobs\Operations\ExecuteUniversalLdapSyncJob;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapSyncBatch;
use App\Services\Operations\OperationJobFactory;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

class DirectoryManagementSyncDispatcher
{
    public function queueUsers(?int $ldapConnectionId = null): array
    {
        $connection = $this->resolveConnection($ldapConnectionId);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No active LDAP connection found.',
            ];
        }

        return $this->queueUniversalSync(
            connection: $connection,
            name: 'Users Sync - '.$connection->name,
            sourcePage: 'directory.users',
            syncScope: 'full',
            baseDn: (string) $connection->base_dn,
            customTargetDn: null,
            searchScope: 'sub',
            filter: '(|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=user))',
            attributes: '*',
            sizeLimit: 100000,
            pageSize: 1000,
        );
    }

    public function queueDirectoryObjects(?int $ldapConnectionId = null): array
    {
        $connection = $this->resolveConnection($ldapConnectionId);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No active LDAP connection found.',
            ];
        }

        return $this->queueUniversalSync(
            connection: $connection,
            name: 'Directory Objects Sync - '.$connection->name,
            sourcePage: 'directory.object_manager',
            syncScope: 'full',
            baseDn: (string) $connection->base_dn,
            customTargetDn: null,
            searchScope: 'sub',
            filter: '(objectClass=*)',
            attributes: '* +',
            sizeLimit: 200000,
            pageSize: 1000,
        );
    }

    public function queueCustomDirectorySync(
        ?int $ldapConnectionId,
        string $name,
        string $baseDn,
        string $filter = '(objectClass=*)',
        string $attributes = '* +',
        string $searchScope = 'sub',
        int $sizeLimit = 200000,
        int $pageSize = 1000,
    ): array {
        $connection = $this->resolveConnection($ldapConnectionId);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No active LDAP connection found.',
            ];
        }

        return $this->queueUniversalSync(
            connection: $connection,
            name: $name,
            sourcePage: 'directory.custom',
            syncScope: 'custom_dn',
            baseDn: (string) $connection->base_dn,
            customTargetDn: $baseDn,
            searchScope: $searchScope,
            filter: $filter,
            attributes: $attributes,
            sizeLimit: $sizeLimit,
            pageSize: $pageSize,
        );
    }


    public function queueSingleDn(
        ?int $ldapConnectionId,
        string $dn,
        string $name = 'Single DN Sync',
        string $sourcePage = 'directory.detail',
        string $filter = '(objectClass=*)',
        string $attributes = '* +',
    ): array {
        $connection = $this->resolveConnection($ldapConnectionId);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No active LDAP connection found.',
            ];
        }

        $dn = trim($dn);

        if ($dn === '') {
            return [
                'ok' => false,
                'message' => 'DN is empty.',
            ];
        }

        return $this->queueUniversalSync(
            connection: $connection,
            name: $name,
            sourcePage: $sourcePage,
            syncScope: 'custom_dn',
            baseDn: (string) $connection->base_dn,
            customTargetDn: $dn,
            searchScope: 'base',
            filter: $filter,
            attributes: $attributes,
            sizeLimit: 1,
            pageSize: 1,
        );
    }

    public function queueSchemaItem(
        ?int $ldapConnectionId,
        ?string $sourceDn,
        ?string $schemaType,
        ?string $primaryName,
    ): array {
        $connection = $this->resolveConnection($ldapConnectionId);

        if (! $connection) {
            return [
                'ok' => false,
                'message' => 'No active LDAP connection found.',
            ];
        }

        $sourceDn = trim((string) ($sourceDn ?: 'cn=Subschema'));
        $schemaType = trim((string) $schemaType);
        $primaryName = trim((string) $primaryName);

        $attribute = match ($schemaType) {
            'object_class', 'objectClass', 'object_classes' => 'objectClasses',
            'attribute_type', 'attributeType', 'attribute_types' => 'attributeTypes',
            'matching_rule', 'matchingRule', 'matching_rules' => 'matchingRules',
            'syntax', 'ldap_syntax', 'ldapSyntaxes' => 'ldapSyntaxes',
            default => '*',
        };

        return $this->queueUniversalSync(
            connection: $connection,
            name: 'Schema Item Sync - '.($primaryName !== '' ? $primaryName : $connection->name),
            sourcePage: 'directory.schema_browser.item',
            syncScope: 'custom_dn',
            baseDn: (string) $connection->base_dn,
            customTargetDn: $sourceDn,
            searchScope: 'base',
            filter: '(objectClass=*)',
            attributes: $attribute,
            sizeLimit: 1,
            pageSize: 1,
        );
    }

    private function queueUniversalSync(
        LdapConnection $connection,
        string $name,
        string $sourcePage,
        string $syncScope,
        string $baseDn,
        ?string $customTargetDn,
        string $searchScope,
        string $filter,
        string $attributes,
        int $sizeLimit,
        int $pageSize,
    ): array {
        try {
            $batch = LdapSyncBatch::query()->create([
                'name' => $name,
                'ldap_connection_id' => $connection->id,
                'status' => 'queued',
                'sync_scope' => $syncScope,
                'base_dn' => $baseDn,
                'custom_target_dn' => $customTargetDn,
                'search_scope' => $searchScope,
                'filter' => $filter,
                'attributes' => $attributes,
                'size_limit' => $sizeLimit,
                'page_size' => $pageSize,
                'safe_mode' => true,
                'preview_mode' => false,
                'destructive' => false,
                'created_by' => Auth::id(),
                'metadata' => [
                    'source_page' => $sourcePage,
                    'queued_from' => 'directory_management',
                    'read_only' => true,
                    'ldap_will_change' => false,
                ],
            ]);

            if (blank($batch->effective_base_dn)) {
                $batch->forceFill([
                    'status' => 'failed',
                    'message' => 'Effective Sync DN is empty.',
                    'failed_entries' => 1,
                    'finished_at' => now(),
                ])->save();

                return [
                    'ok' => false,
                    'message' => 'Effective Sync DN is empty.',
                    'batch' => $batch,
                    'operation_job' => null,
                ];
            }

            $operationJob = app(OperationJobFactory::class)->createQueued([
                'operation_type' => 'universal_ldap_sync',
                'operation_action' => 'execute_universal_ldap_sync',
                'module' => $sourcePage,
                'title' => $name,
                'queue_name' => 'operations',
                'source' => 'filament',
                'target_type' => LdapSyncBatch::class,
                'target_key' => (string) $batch->id,
                'target_dn' => $batch->effective_base_dn,
                'ldap_connection_id' => $connection->id,
                'created_by' => Auth::id(),
                'total_items' => 1,
                'pending_items' => 1,
                'payload' => [
                    'ldap_sync_batch_id' => $batch->id,
                    'ldap_connection_id' => $connection->id,
                    'effective_base_dn' => $batch->effective_base_dn,
                    'filter' => $filter,
                    'search_scope' => $searchScope,
                    'attributes' => $attributes,
                    'size_limit' => $sizeLimit,
                    'page_size' => $pageSize,
                    'source_page' => $sourcePage,
                ],
                'metadata' => [
                    'ldap_sync_batch_id' => $batch->id,
                    'source_page' => $sourcePage,
                    'effective_base_dn' => $batch->effective_base_dn,
                    'filter' => $filter,
                    'search_scope' => $searchScope,
                    'safe_mode' => true,
                    'destructive' => false,
                ],
            ]);

            $batch->forceFill([
                'status' => 'queued',
                'operation_job_id' => $operationJob->id,
                'message' => 'LDAP sync queued from '.$sourcePage.'.',
            ])->save();

            ExecuteUniversalLdapSyncJob::dispatch($operationJob->id, $batch->id);

            return [
                'ok' => true,
                'message' => 'LDAP sync queued.',
                'batch' => $batch,
                'operation_job' => $operationJob,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'batch' => null,
                'operation_job' => null,
            ];
        }
    }

    private function resolveConnection(?int $ldapConnectionId = null): ?LdapConnection
    {
        if ($ldapConnectionId) {
            $connection = LdapConnection::query()
                ->whereKey($ldapConnectionId)
                ->where('is_active', true)
                ->first();

            if ($connection) {
                return $connection;
            }
        }

        return LdapConnection::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    public static function notifyResult(array $result, string $successTitle): void
    {
        if ($result['ok'] ?? false) {
            Notification::make()
                ->title($successTitle)
                ->body('Batch ID: '.($result['batch']?->id ?? 'N/A').' | Operation Job ID: '.($result['operation_job']?->id ?? 'N/A'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Sync queue failed')
            ->body((string) ($result['message'] ?? 'Unknown error'))
            ->danger()
            ->send();
    }
}
