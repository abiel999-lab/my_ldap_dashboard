<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use App\Models\Operations\LdapCrudOperation;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                ->using(function (array $data): Model {
                    return $this->createLdapBulkOperationSafely($data);
                }),
        ];
    }

    private function createLdapBulkOperationSafely(array $data): LdapCrudOperation
    {
        $table = 'ldap_crud_operations';

        $columns = Schema::getColumnListing($table);
        $columnTypes = $this->columnTypes($table);
        $nullableColumns = $this->nullableColumns($table);

        $operationKind = $data['operation_kind'] ?? $data['operation_type'] ?? 'add_attribute';
        $baseDn = $data['base_dn'] ?? $data['custom_target_dn'] ?? '';
        $targetDn = $data['custom_target_dn'] ?? $baseDn;
        $filter = $data['ldap_filter'] ?? '(objectClass=*)';
        $scope = $data['search_scope'] ?? 'subtree';

        $defaults = [
            'name' => $data['name'] ?? 'LDAP Bulk Operation',
            'operation_type' => $operationKind,
            'operation_kind' => $operationKind,
            'status' => 'draft',

            'mode' => 'bulk',
            'source_mode' => 'ldap_query',
            'target_mode' => $data['target_mode'] ?? 'base_dn',

            'ldap_connection_id' => $data['ldap_connection_id'] ?? null,
            'base_dn' => $baseDn,
            'target_dn' => $targetDn,
            'custom_target_dn' => $data['custom_target_dn'] ?? null,

            'ldap_filter' => $filter,
            'search_filter' => $filter,
            'filter' => $filter,

            'search_scope' => $scope,
            'scope' => $scope,
            'size_limit' => $data['size_limit'] ?? 100,

            'objectclass_name' => $data['objectclass_name'] ?? null,
            'attribute_name' => $data['attribute_name'] ?? null,
            'attribute_value' => $data['attribute_value'] ?? null,
            'target_ou_dn' => $data['target_ou_dn'] ?? null,
            'existing_value_behavior' => $data['existing_value_behavior'] ?? 'skip',
            'missing_objectclass_behavior' => $data['missing_objectclass_behavior'] ?? 'skip',

            'skip_if_invalid' => $data['skip_if_invalid'] ?? true,
            'require_preview' => $data['require_preview'] ?? true,
            'delete_related_objectclass_attributes' => $data['delete_related_objectclass_attributes'] ?? true,
            'queue_threshold' => $data['queue_threshold'] ?? 200,

            'objectclass_must_values' => $data['objectclass_must_values'] ?? [],
            'payload' => $data,
            'result' => [],
            'preview_result' => null,
            'execution_result' => null,
            'rollback_payload' => null,
            'rollback_result' => null,

            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'user_id' => Auth::id(),
            'actor_id' => Auth::id(),
        ];

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }

            $type = strtolower((string) ($columnTypes[$key] ?? ''));

            if ($this->isIntegerType($type)) {
                if (in_array($key, ['created_by', 'updated_by', 'user_id', 'actor_id'], true)) {
                    $value = Auth::id();
                } elseif ($value === '' || $value === null) {
                    $value = null;
                } elseif (! is_numeric($value)) {
                    $value = null;
                } else {
                    $value = (int) $value;
                }
            }

            if ($this->isBooleanType($type)) {
                $value = (bool) $value;
            }

            if ($this->isJsonType($type) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }

            if ($value === null && ! in_array($key, $nullableColumns, true)) {
                $value = $this->defaultForType($type, $key);
            }

            $clean[$key] = $value;
        }

        foreach ($columns as $column) {
            if (in_array($column, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if (array_key_exists($column, $clean)) {
                continue;
            }

            if (in_array($column, $nullableColumns, true)) {
                continue;
            }

            $type = strtolower((string) ($columnTypes[$column] ?? ''));
            $clean[$column] = $this->defaultForType($type, $column);
        }

        return LdapCrudOperation::query()->create($clean);
    }

    private function columnTypes(string $table): array
    {
        $rows = DB::select(
            "select column_name, data_type
             from information_schema.columns
             where table_schema = current_schema()
             and table_name = ?",
            [$table]
        );

        $types = [];

        foreach ($rows as $row) {
            $types[$row->column_name] = $row->data_type;
        }

        return $types;
    }

    private function nullableColumns(string $table): array
    {
        $rows = DB::select(
            "select column_name
             from information_schema.columns
             where table_schema = current_schema()
             and table_name = ?
             and is_nullable = 'YES'",
            [$table]
        );

        return array_map(fn ($row) => $row->column_name, $rows);
    }

    private function isIntegerType(string $type): bool
    {
        return in_array($type, [
            'bigint',
            'integer',
            'smallint',
        ], true);
    }

    private function isBooleanType(string $type): bool
    {
        return $type === 'boolean';
    }

    private function isJsonType(string $type): bool
    {
        return in_array($type, [
            'json',
            'jsonb',
        ], true);
    }

    private function defaultForType(string $type, string $column): mixed
    {
        if ($this->isIntegerType($type)) {
            if (in_array($column, ['created_by', 'updated_by', 'user_id', 'actor_id'], true)) {
                return Auth::id() ?: 1;
            }

            return 0;
        }

        if ($this->isBooleanType($type)) {
            return false;
        }

        if ($this->isJsonType($type)) {
            return [];
        }

        if (str_contains($type, 'timestamp') || str_contains($type, 'date')) {
            return now();
        }

        if ($column === 'status') {
            return 'draft';
        }

        if ($column === 'name') {
            return 'LDAP Bulk Operation';
        }

        return '';
    }
}
