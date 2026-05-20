<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SmartImportBatchResolver
{
    public function createImportBatch(array $data): int
    {
        if (! Schema::hasTable('import_batches')) {
            throw new \RuntimeException('import_batches table does not exist.');
        }

        $template = $this->template((int) ($data['import_template_id'] ?? 0));
        $templateMetadata = $this->decodeMetadata($template['metadata'] ?? []);

        $targetLdapId = (int) $this->firstFilled([
            $data['ldap_connection_id'] ?? null,
            $template['ldap_connection_id'] ?? null,
        ]);

        if ($targetLdapId <= 0) {
            throw new \RuntimeException('Target LDAP is required.');
        }

        $uploadPath = (string) ($data['upload_file'] ?? '');

        if ($uploadPath === '') {
            throw new \RuntimeException('Upload file is required.');
        }

        $disk = 'local';

        if (! Storage::disk($disk)->exists($uploadPath)) {
            throw new \RuntimeException('Uploaded file not found: '.$uploadPath);
        }

        $originalFilename = $this->firstFilled([
            $data['original_filename'] ?? null,
            basename($uploadPath),
        ]);

        $fileSize = Storage::disk($disk)->size($uploadPath);
        $sha256 = hash_file('sha256', Storage::disk($disk)->path($uploadPath));

        $importType = strtolower((string) $this->firstFilled([
            $data['import_type'] ?? null,
            $templateMetadata['file_format'] ?? null,
            $template['file_format'] ?? null,
            pathinfo($uploadPath, PATHINFO_EXTENSION),
            'csv',
        ]));

        if (! in_array($importType, ['csv', 'ldif', 'json'], true)) {
            $importType = 'csv';
        }

        $operationMode = (string) $this->firstFilled([
            $data['operation_mode'] ?? null,
            $templateMetadata['operation_mode'] ?? null,
            'create',
        ]);

        $dnRules = $templateMetadata['dn_rules'] ?? [];
        $objectRules = $templateMetadata['object_rules'] ?? [];
        $safetyRules = $templateMetadata['safety_rules'] ?? [];

        $baseDn = (string) $this->firstFilled([
            $data['base_dn'] ?? null,
            $dnRules['base_dn'] ?? null,
            'dc=example,dc=local',
        ]);

        $targetParentDn = (string) $this->firstFilled([
            $data['target_parent_dn'] ?? null,
            $dnRules['target_parent_dn'] ?? null,
            '',
        ]);

        $rdnAttribute = (string) $this->firstFilled([
            $data['identifier_attribute'] ?? null,
            $dnRules['identifier_attribute'] ?? null,
            $dnRules['rdn_attribute'] ?? null,
            'uid',
        ]);

        $dnTemplate = (string) $this->firstFilled([
            $data['dn_template'] ?? null,
            $dnRules['dn_template'] ?? null,
            $rdnAttribute.'={'.$rdnAttribute.'},'.$targetParentDn,
        ]);

        $ifTargetExists = (string) $this->firstFilled([
            $data['if_target_exists'] ?? null,
            $safetyRules['if_target_exists'] ?? null,
            'skip',
        ]);

        $safeMode = (bool) ($data['safe_mode'] ?? $safetyRules['safe_mode'] ?? true);
        $previewOnly = (bool) ($data['preview_only'] ?? true);
        $allowDestructive = (bool) ($data['allow_destructive_operation'] ?? $safetyRules['allow_destructive_operation'] ?? false);

        $name = (string) $this->firstFilled([
            $data['name'] ?? null,
            $this->autoName($operationMode, $importType, $template['name'] ?? null),
        ]);

        $metadata = [
            'smart_import_version' => 1,
            'generated_by' => 'SmartImportBatchResolver',
            'generated_at' => now()->toDateTimeString(),

            'template' => [
                'id' => $template['id'] ?? null,
                'name' => $template['name'] ?? null,
                'metadata' => $templateMetadata,
            ],

            'operation' => [
                'mode' => $operationMode,
                'if_target_exists' => $ifTargetExists,
            ],

            'dn_rules' => [
                'base_dn' => $baseDn,
                'target_parent_dn' => $targetParentDn,
                'rdn_attribute' => $rdnAttribute,
                'identifier_attribute' => $rdnAttribute,
                'dn_template' => $dnTemplate,
                'dn_strategy' => $dnRules['dn_strategy'] ?? 'dn_template',
                'multi_dn_rules' => $dnRules['multi_dn_rules'] ?? '',
            ],

            'object_rules' => [
                'object_type' => $objectRules['object_type'] ?? $templateMetadata['object_type'] ?? null,
                'object_classes' => $objectRules['object_classes'] ?? [],
                'required_attributes' => $objectRules['required_attributes'] ?? [],
                'optional_attributes' => $objectRules['optional_attributes'] ?? [],
                'attribute_mapping' => $objectRules['attribute_mapping'] ?? [],
                'default_values' => $objectRules['default_values'] ?? [],
                'multi_value_separator' => $objectRules['multi_value_separator'] ?? ';',
            ],

            'safety' => [
                'safe_mode' => $safeMode,
                'preview_only' => $previewOnly,
                'allow_destructive_operation' => $allowDestructive,
                'preview_required' => true,
            ],
        ];

        $record = [
            'uuid' => (string) Str::uuid(),
            'name' => $name,

            'ldap_connection_id' => $targetLdapId,
            'import_template_id' => $template['id'] ?? null,

            'import_type' => $importType,
            'type' => $importType,
            'source_type' => 'upload',

            'status' => 'draft',
            'message' => 'Smart import batch created. Generate preview before applying.',

            'file_disk' => $disk,
            'file_path' => $uploadPath,
            'original_filename' => $originalFilename,
            'file_size' => $fileSize,
            'sha256' => $sha256,

            'base_dn' => $baseDn,
            'target_base_dn' => $baseDn,
            'target_parent_dn' => $targetParentDn,
            'identifier_attribute' => $rdnAttribute,
            'rdn_attribute' => $rdnAttribute,
            'dn_template' => $dnTemplate,

            'operation_mode' => $operationMode,
            'if_target_exists' => $ifTargetExists,

            'safe_mode' => $safeMode,
            'preview_only' => $previewOnly,
            'allow_destructive_operation' => $allowDestructive,

            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'conflict_rows' => 0,
            'will_create_rows' => 0,
            'will_update_rows' => 0,
            'will_skip_rows' => 0,
            'will_fail_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,

            'metadata' => $metadata,

            'created_at' => now(),
            'updated_at' => now(),
        ];

        return $this->insertSmartRecord('import_batches', $record);
    }

    private function template(int $id): array
    {
        if ($id <= 0 || ! Schema::hasTable('import_templates')) {
            return [];
        }

        $row = DB::table('import_templates')->where('id', $id)->first();

        return $row ? (array) $row : [];
    }

    private function insertSmartRecord(string $table, array $record): int
    {
        $columns = Schema::getColumnListing($table);

        $columnTypes = collect(DB::select("
            select column_name, data_type, udt_name
            from information_schema.columns
            where table_schema = 'public'
            and table_name = ?
        ", [$table]))
            ->mapWithKeys(fn ($column) => [
                $column->column_name => strtolower((string) ($column->data_type ?: $column->udt_name)),
            ])
            ->all();

        $jsonColumns = collect($columnTypes)
            ->filter(fn ($type) => str_contains($type, 'json'))
            ->keys()
            ->all();

        $booleanColumns = collect($columnTypes)
            ->filter(fn ($type) => str_contains($type, 'boolean') || $type === 'bool')
            ->keys()
            ->all();

        $clean = [];

        foreach ($record as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }

            if (in_array($key, $jsonColumns, true)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (in_array($key, $booleanColumns, true)) {
                $value = (bool) $value;
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $clean[$key] = $value;
        }

        if ($clean === []) {
            throw new \RuntimeException('No insertable columns for '.$table);
        }

        return (int) DB::table($table)->insertGetId($clean);
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function autoName(string $operationMode, string $importType, ?string $templateName): string
    {
        $templateName = $templateName ?: 'Manual Import';

        return trim('Import '.strtoupper($importType).' '.ucfirst($operationMode).' - '.$templateName);
    }

    private function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }
}
