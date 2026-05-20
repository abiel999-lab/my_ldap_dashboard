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
use Illuminate\Support\Str;

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
                    return $this->hardCreate($data);
                }),
        ];
    }

    private function hardCreate(array $data): LdapCrudOperation
    {
        $table = 'ldap_crud_operations';

        $meta = $this->columnMeta($table);
        $columns = array_keys($meta);

        $operationKind = $data['operation_kind'] ?? $data['operation_type'] ?? 'add_objectclass';
        $baseDn = $data['base_dn'] ?? $data['custom_target_dn'] ?? '';
        $targetDn = $data['custom_target_dn'] ?? $baseDn;
        $filter = $data['ldap_filter'] ?? '(objectClass=*)';
        $scope = $data['search_scope'] ?? 'subtree';
        $now = now();

        $payload = [
            'uuid' => (string) Str::uuid(),

            'name' => $data['name'] ?? 'LDAP Bulk Operation',
            'operation_type' => $operationKind,
            'operation_kind' => $operationKind,
            'status' => 'draft',

            'ldap_connection_id' => $data['ldap_connection_id'] ?? null,

            'mode' => 'bulk',
            'source_mode' => 'ldap_query',
            'source_input_mode' => 'ldap_query',
            'target_mode' => $data['target_mode'] ?? 'base_dn',

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

            'safe_mode' => true,
            'dry_run' => false,
            'destructive' => false,
            'approval_required' => false,

            'objectclass_must_values' => $data['objectclass_must_values'] ?? [],
            'payload' => $data,
            'metadata' => $data,
            'result' => [],
            'preview_result' => null,
            'execution_result' => null,
            'rollback_payload' => null,
            'rollback_result' => null,

            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'user_id' => Auth::id(),
            'actor_id' => Auth::id(),

            'created_at' => $now,
            'updated_at' => $now,
        ];

        $insert = [];

        foreach ($columns as $column) {
            if ($column === 'id') {
                continue;
            }

            $value = array_key_exists($column, $payload)
                ? $payload[$column]
                : null;

            $insert[$column] = $this->normalizeValue($column, $value, $meta[$column], $data);
        }

        $id = DB::table($table)->insertGetId($insert);

        return LdapCrudOperation::query()->findOrFail($id);
    }

    private function columnMeta(string $table): array
    {
        $rows = DB::select(
            "select
                column_name,
                data_type,
                is_nullable,
                column_default,
                character_maximum_length
            from information_schema.columns
            where table_schema = current_schema()
            and table_name = ?
            order by ordinal_position",
            [$table]
        );

        $meta = [];

        foreach ($rows as $row) {
            $meta[$row->column_name] = [
                'type' => strtolower((string) $row->data_type),
                'nullable' => $row->is_nullable === 'YES',
                'default' => $row->column_default,
                'max' => $row->character_maximum_length ? (int) $row->character_maximum_length : null,
            ];
        }

        return $meta;
    }

    private function normalizeValue(string $column, mixed $value, array $meta, array $originalData): mixed
    {
        $type = $meta['type'];
        $nullable = (bool) $meta['nullable'];
        $max = $meta['max'];

        if ($type === 'uuid') {
            if (blank($value)) {
                return (string) Str::uuid();
            }

            return (string) $value;
        }

        if (in_array($type, ['bigint', 'integer', 'smallint'], true)) {
            if (in_array($column, ['created_by', 'updated_by', 'user_id', 'actor_id'], true)) {
                return Auth::id() ?: 1;
            }

            if ($value === null || $value === '') {
                return $nullable ? null : 0;
            }

            return is_numeric($value) ? (int) $value : ($nullable ? null : 0);
        }

        if ($type === 'boolean') {
            return (bool) $value;
        }

        if (in_array($type, ['json', 'jsonb'], true)) {
            if ($value === null || $value === '') {
                return $nullable ? null : json_encode([]);
            }

            if (is_string($value)) {
                json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $value;
                }

                return json_encode($value);
            }

            return json_encode($value);
        }

        if (str_contains($type, 'timestamp') || $type === 'date') {
            if ($value) {
                return $value;
            }

            return $nullable ? null : now();
        }

        if ($value === null) {
            if ($nullable) {
                return null;
            }

            $value = $this->stringDefault($column, $originalData);
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        $value = (string) $value;

        if ($max && strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    }

    private function stringDefault(string $column, array $data): string
    {
        return match ($column) {
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'] ?? 'LDAP Bulk Operation',
            'operation_type' => $data['operation_kind'] ?? 'add_objectclass',
            'operation_kind' => $data['operation_kind'] ?? 'add_objectclass',
            'status' => 'draft',
            'mode' => 'bulk',
            'source_mode' => 'ldap_query',
            'source_input_mode' => 'ldap_query',
            'target_mode' => $data['target_mode'] ?? 'base_dn',
            'search_scope', 'scope' => $data['search_scope'] ?? 'subtree',
            'ldap_filter', 'filter', 'search_filter' => $data['ldap_filter'] ?? '(objectClass=*)',
            'base_dn', 'target_dn' => $data['base_dn'] ?? '',
            'existing_value_behavior', 'missing_objectclass_behavior' => 'skip',
            default => '',
        };
    }
}
