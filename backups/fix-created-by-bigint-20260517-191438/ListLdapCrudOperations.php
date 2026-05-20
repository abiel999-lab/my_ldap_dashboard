<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ListLdapCrudOperations extends ListRecords
{
    protected static string $resource = LdapCrudOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New LDAP Bulk Operation')
                ->modalHeading('Create LDAP Bulk Operation')
                ->modalSubmitActionLabel('Create')
                ->modalWidth('7xl')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $table = 'ldap_crud_operations';

                    $operationKind = $data['operation_kind'] ?? $data['operation_type'] ?? 'add_attribute';
                    $baseDn = $data['base_dn'] ?? $data['custom_target_dn'] ?? '';
                    $targetDn = $data['custom_target_dn'] ?? $baseDn;
                    $filter = $data['ldap_filter'] ?? '(objectClass=*)';

                    $defaults = [
                        'operation_type' => $operationKind,
                        'operation_kind' => $operationKind,
                        'name' => $data['name'] ?? 'LDAP Bulk Operation',
                        'status' => 'draft',
                        'mode' => 'bulk',
                        'source_mode' => 'ldap_query',
                        'target_mode' => $data['target_mode'] ?? 'base_dn',
                        'base_dn' => $baseDn,
                        'target_dn' => $targetDn,
                        'custom_target_dn' => $data['custom_target_dn'] ?? null,
                        'ldap_filter' => $filter,
                        'search_filter' => $filter,
                        'filter' => $filter,
                        'search_scope' => $data['search_scope'] ?? 'subtree',
                        'scope' => $data['search_scope'] ?? 'subtree',
                        'size_limit' => $data['size_limit'] ?? 100,
                        'payload' => $data,
                        'result' => [],
                        'preview_result' => null,
                        'execution_result' => null,
                        'rollback_payload' => null,
                        'rollback_result' => null,
                        'created_by' => Auth::user()?->email ?? Auth::user()?->name ?? 'system',
                    ];

                    foreach ($defaults as $column => $value) {
                        if (
                            Schema::hasColumn($table, $column)
                            && ! array_key_exists($column, $data)
                        ) {
                            $data[$column] = $value;
                        }
                    }

                    if (Schema::hasColumn($table, 'operation_type')) {
                        $data['operation_type'] = $operationKind;
                    }

                    if (Schema::hasColumn($table, 'operation_kind')) {
                        $data['operation_kind'] = $operationKind;
                    }

                    if (Schema::hasColumn($table, 'status')) {
                        $data['status'] = 'draft';
                    }

                    return $data;
                }),
        ];
    }
}
