<?php

namespace App\Services\Operations;

use App\Services\Operations\LdapImportDependencyGraphService;
use App\Models\Operations\ImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LdapImportPreviewService
{
    public function preview(ImportBatch $batch): array
    {
        if (strtolower((string) ($batch->type ?? '')) === 'ldif') {
            $filePath = trim((string) ($batch->file_path ?? ''));

            $raw = '';

            foreach ([
                storage_path('app/'.$filePath),
                storage_path('app/private/'.$filePath),
                storage_path('app/public/'.$filePath),
            ] as $candidate) {
                if ($filePath !== '' && file_exists($candidate)) {
                    $raw = (string) file_get_contents($candidate);
                    break;
                }
            }

            if (trim($raw) === '') {
                return [
                    'ok' => false,
                    'message' => 'LDIF file not found or empty: '.$filePath,
                ];
            }

            $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw))."\n";

            $dn = '';
            if (preg_match('/^dn:\s*(.+)$/mi', $raw, $m)) {
                $dn = trim($m[1]);
            }

            \DB::table('import_rows')->where('import_batch_id', $batch->id)->delete();

            \DB::table('import_rows')->insert([
                'import_batch_id' => $batch->id,
                'row_number' => 1,
                'status' => 'valid',
                'target_dn' => $dn,
                'raw_payload' => json_encode(['raw_ldif' => true], JSON_UNESCAPED_SLASHES),
                'mapped_payload' => json_encode(['raw_ldif' => true], JSON_UNESCAPED_SLASHES),
                'generated_ldif' => $raw,
                'message' => 'Raw LDIF passthrough. Original uploaded LDIF is used without regeneration.',
                'conflict_reason' => null,
                'validation_errors' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $previewPath = 'imports/preview-ldif/import-batch-'.$batch->id.'.ldif';
            \Storage::disk('local')->put($previewPath, $raw);

            $batch->forceFill([
                'preview_ldif_path' => $previewPath,
                'preview_ldif_hash' => hash('sha256', $raw),
                'status' => 'ldif_preview_completed',
                'message' => 'Raw LDIF preview generated from uploaded file.',
                'total_rows' => 1,
                'valid_rows' => 1,
                'invalid_rows' => 0,
                'failed_rows' => 0,
            ])->save();

            return [
                'ok' => true,
                'message' => 'Raw LDIF preview generated from uploaded file.',
                'summary' => [
                    'total_rows' => 1,
                    'valid_rows' => 1,
                    'invalid_rows' => 0,
                    'preview_ldif_path' => $previewPath,
                ],
            ];
        }

        try {
            $this->updateBatch($batch, [
                'status' => 'preview_running',
                'message' => 'Generating LDAP import preview.',
                'error_message' => null,
            ]);

            $batch = $batch->fresh();

            if (! $batch) {
                throw new \RuntimeException('Import batch not found.');
            }

            $metadata = $this->metadata($batch);
            $templateMetadata = $metadata['template']['metadata'] ?? null;

            if (is_string($templateMetadata)) {
                $decodedTemplate = json_decode($templateMetadata, true);
                $templateMetadata = is_array($decodedTemplate) ? $decodedTemplate : [];
            }

            if (! is_array($templateMetadata)) {
                $templateMetadata = [];
            }

            $fileDisk = (string) ($batch->file_disk ?: 'local');
            $filePath = (string) ($batch->file_path ?: '');

            if ($filePath === '') {
                throw new \RuntimeException('Import file path is empty.');
            }

            if (! Storage::disk($fileDisk)->exists($filePath)) {
                throw new \RuntimeException('Import file not found on disk: '.$fileDisk.':'.$filePath);
            }

            $raw = Storage::disk($fileDisk)->get($filePath);
            $importType = strtolower((string) ($batch->import_type ?: 'csv'));

            $rows = match ($importType) {
                'ldif' => $this->parseLdif($raw),
                'json' => $this->parseJson($raw),
                default => $this->parseCsv($raw),
            };

            $ldapConnectionId = (int) ($batch->ldap_connection_id ?? 0);

            $baseDn = $this->firstFilled([
                $metadata['dn_rules']['base_dn'] ?? null,
                $templateMetadata['dn_rules']['base_dn'] ?? null,
                $batch->base_dn ?? null,
            ]);

            $identifierAttribute = $this->firstFilled([
                $metadata['dn_rules']['identifier_attribute'] ?? null,
                $metadata['dn_rules']['rdn_attribute'] ?? null,
                $templateMetadata['dn_rules']['rdn_attribute'] ?? null,
                $batch->identifier_attribute ?? null,
                'uid',
            ]);

            $dnStrategy = $this->firstFilled([
                $metadata['dn_rules']['dn_strategy'] ?? null,
                $templateMetadata['dn_rules']['dn_strategy'] ?? null,
                'rdn_parent',
            ]);

            $operationMode = $this->firstFilled([
                $metadata['operation']['mode'] ?? null,
                $templateMetadata['operation_mode'] ?? null,
                'create',
            ]);

            $ifTargetExists = $this->firstFilled([
                $metadata['operation']['if_target_exists'] ?? null,
                $templateMetadata['safety_rules']['if_target_exists'] ?? null,
                'skip',
            ]);

            $targetParentDn = $this->firstFilled([
                $metadata['dn_rules']['target_parent_dn'] ?? null,
                $templateMetadata['dn_rules']['target_parent_dn'] ?? null,
                null,
            ]);

            $dnTemplate = $this->firstFilled([
                $metadata['dn_rules']['dn_template'] ?? null,
                $templateMetadata['dn_rules']['dn_template'] ?? null,
                $batch->dn_template ?? null,
            ]);

            $multiDnRules = (string) ($metadata['dn_rules']['multi_dn_rules'] ?? $templateMetadata['dn_rules']['multi_dn_rules'] ?? '');

            $templateObjectClasses = $this->lines($templateMetadata['object_rules']['object_classes'] ?? "top\nperson\norganizationalPerson\ninetOrgPerson");
            $templateRequiredAttributes = $this->lines($templateMetadata['object_rules']['required_attributes'] ?? $identifierAttribute);
            $attributeMapping = $this->keyValueLines($templateMetadata['object_rules']['attribute_mapping'] ?? '');
            $defaultValues = $this->keyValueLines($templateMetadata['object_rules']['default_values'] ?? '');
            $excludedAttributes = $this->lines($templateMetadata['safety_rules']['excluded_attributes'] ?? "entryUUID\nentryCSN\ncreateTimestamp\ncreatorsName\nmodifyTimestamp\nmodifiersName");

            $schemaObjects = $this->loadObjectClassSchema($ldapConnectionId);

            $total = 0;
            $valid = 0;
            $invalid = 0;
            $willCreate = 0;
            $willUpdate = 0;
            $willSkip = 0;
            $willFail = 0;

            $previewBlocks = [];
            $rowSummaries = [];

            foreach ($rows as $index => $row) {
                $total++;

                $rowNumber = $index + 1;

                $dn = '';

                if ($importType === 'ldif' && isset($row['dn'])) {
                    $dn = (string) $row['dn'];
                } elseif (isset($row['dn']) && filled($row['dn'])) {
                    $dn = (string) $row['dn'];
                } else {
                    $dn = $this->buildDn(
                        row: $row,
                        baseDn: (string) $baseDn,
                        identifierAttribute: (string) $identifierAttribute,
                        dnStrategy: (string) $dnStrategy,
                        targetParentDn: $targetParentDn,
                        dnTemplate: $dnTemplate,
                        multiDnRules: $multiDnRules,
                    );
                }

                $targetIdentifier = (string) ($row[$identifierAttribute] ?? '');

                $rowObjectClasses = $this->resolveObjectClassesForRow($row, $templateObjectClasses);
                $schemaValidation = $this->validateObjectClassesAgainstSchema(
                    objectClasses: $rowObjectClasses,
                    schemaObjects: $schemaObjects,
                );

                $schemaMustAttributes = $schemaValidation['must_attributes'] ?? [];
                $schemaMayAttributes = $schemaValidation['may_attributes'] ?? [];

                $requiredAttributes = array_values(array_unique(array_filter(array_merge(
                    $templateRequiredAttributes,
                    $schemaMustAttributes,
                ))));

                $mappedPayload = $this->mapPayload(
                    row: $row,
                    attributeMapping: $attributeMapping,
                    defaultValues: $defaultValues,
                    excludedAttributes: $excludedAttributes,
                );

                $errors = [];
                $warnings = $schemaValidation['warnings'] ?? [];

                if ($dn === '') {
                    $errors[] = 'DN could not be generated.';
                }

                foreach ($schemaValidation['errors'] ?? [] as $schemaError) {
                    $errors[] = $schemaError;
                }

                foreach ($requiredAttributes as $requiredAttribute) {
                    if ($requiredAttribute === '') {
                        continue;
                    }

                    if (
                        ! array_key_exists($requiredAttribute, $row)
                        && ! array_key_exists($requiredAttribute, $mappedPayload)
                        && ! array_key_exists($requiredAttribute, $defaultValues)
                    ) {
                        $errors[] = 'Missing required attribute: '.$requiredAttribute;
                    }
                }

                foreach ($this->validateAttributesAgainstSchema($mappedPayload, $schemaMustAttributes, $schemaMayAttributes, $excludedAttributes) as $warning) {
                    $warnings[] = $warning;
                }

                if ($errors !== []) {
                    $invalid++;
                    $willFail++;

                    $invalidLdif = '# ROW '.$rowNumber.' INVALID: '.implode('; ', $errors);

                    $previewBlocks[] = $invalidLdif;

                    $rowSummaries[] = [
                        'row_number' => $rowNumber,
                        'status' => 'invalid',
                        'operation' => 'fail',
                        'action_plan' => 'fail',
                        'target_identifier' => $targetIdentifier,
                        'target_dn' => $this->normalizeImportDnValue((string) $dn),
                        'message' => implode('; ', $errors),
                        'conflict_reason' => implode('; ', $errors),
                        'validation_errors' => $errors,
                        'warnings' => $warnings,
                        'raw_payload' => $row,
                        'mapped_payload' => $mappedPayload,
                        'preview_ldif' => $invalidLdif,
                    ];

                    continue;
                }

                $valid++;

                $operation = match ($operationMode) {
                    'update' => 'update',
                    'upsert' => 'upsert',
                    'delete' => 'delete',
                    default => 'create',
                };

                if ($operation === 'update') {
                    $willUpdate++;
                } elseif ($operation === 'delete') {
                    $willUpdate++;
                } elseif ($operation === 'upsert') {
                    $willCreate++;
                } else {
                    $willCreate++;
                }

                $ldif = $this->buildLdifBlock(
                    dn: $dn,
                    row: $mappedPayload,
                    operation: $operation,
                    objectClasses: $rowObjectClasses,
                    excludedAttributes: $excludedAttributes,
                );

                $previewBlocks[] = $ldif;

                $rowSummaries[] = [
                    'row_number' => $rowNumber,
                    'status' => 'valid',
                    'operation' => $operation,
                    'action_plan' => $operation,
                    'target_identifier' => $targetIdentifier,
                    'target_dn' => $this->normalizeImportDnValue((string) $dn),
                    'message' => strtoupper($operation).' preview generated.',
                    'conflict_reason' => null,
                    'validation_errors' => [],
                    'warnings' => $warnings,
                    'raw_payload' => $row,
                    'mapped_payload' => $mappedPayload,
                    'preview_ldif' => $ldif,
                    'schema_validation' => [
                        'object_classes' => $rowObjectClasses,
                        'must_attributes' => $schemaMustAttributes,
                        'may_attributes' => $schemaMayAttributes,
                        'structural_classes' => $schemaValidation['structural_classes'] ?? [],
                        'auxiliary_classes' => $schemaValidation['auxiliary_classes'] ?? [],
                    ],
                ];
            }

            $previewLdif = implode("\n\n", $previewBlocks)."\n";

            $status = $invalid > 0 ? 'preview_completed_with_issues' : 'preview_completed';
            $message = $invalid > 0
                ? 'LDAP import preview completed with issues.'
                : 'LDAP import preview completed successfully.';

            $metadata['preview'] = [
                'generated_at' => now()->toDateTimeString(),
                'import_type' => $importType,
                'operation_mode' => $operationMode,
                'if_target_exists' => $ifTargetExists,
                'dn_strategy' => $dnStrategy,
                'base_dn' => $baseDn,
                'identifier_attribute' => $identifierAttribute,
                'target_parent_dn' => $targetParentDn,
                'dn_template' => $dnTemplate,
                'schema_aware' => true,
                'ldap_connection_id' => $ldapConnectionId,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'will_create_rows' => $willCreate,
                'will_update_rows' => $willUpdate,
                'will_skip_rows' => $willSkip,
                'will_fail_rows' => $willFail,
                'rows' => $rowSummaries,
                'preview_ldif' => $previewLdif,
            ];

            $outputPath = 'imports/previews/'.now()->format('Y/m/d').'/import-preview-'.$batch->id.'-'.now()->format('Ymd-His').'.ldif';
            Storage::disk('local')->put($outputPath, $previewLdif);

            $this->updateBatch($batch, [
                'status' => $status,
                'message' => $message,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'will_create_rows' => $willCreate,
                'will_update_rows' => $willUpdate,
                'will_skip_rows' => $willSkip,
                'will_fail_rows' => $willFail,
                'metadata' => $metadata,
                'preview_path' => $outputPath,
                'output_path' => $outputPath,
                'preview_generated_at' => now(),
                'last_previewed_at' => now(),
            ]);

            $this->replaceImportRows($batch, $rowSummaries);

            return [
                'ok' => true,
                'status' => $status,
                'message' => $message,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'will_create_rows' => $willCreate,
                'will_update_rows' => $willUpdate,
                'will_skip_rows' => $willSkip,
                'will_fail_rows' => $willFail,
            ];
        } catch (Throwable $exception) {
            $message = $exception->getMessage().' | '.$exception->getFile().':'.$exception->getLine();

            $this->updateBatch($batch, [
                'status' => 'preview_failed',
                'message' => $message,
                'error_message' => $message,
                'will_fail_rows' => 1,
            ]);

            return [
                'ok' => false,
                'status' => 'preview_failed',
                'message' => $message,
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'will_create_rows' => 0,
                'will_update_rows' => 0,
                'will_skip_rows' => 0,
                'will_fail_rows' => 1,
            ];
        }
    }

    private function parseCsv(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            return [];
        }

        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = [];

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = isset($data[$index]) ? trim((string) $data[$index]) : '';
            }

            if (array_filter($row, fn ($value) => $value !== '') !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function parseJson(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, fn ($row) => is_array($row)));
        }

        return [$decoded];
    }

    private function parseLdif(string $raw): array
    {
        $blocks = preg_split("/\n\s*\n/", trim($raw)) ?: [];
        $rows = [];

        foreach ($blocks as $block) {
            $row = [];

            foreach (preg_split("/\r?\n/", trim($block)) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                if (isset($row[$key])) {
                    if (! is_array($row[$key])) {
                        $row[$key] = [$row[$key]];
                    }

                    $row[$key][] = $value;
                } else {
                    $row[$key] = $value;
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function buildDn(array $row, string $baseDn, string $identifierAttribute, string $dnStrategy, ?string $targetParentDn, ?string $dnTemplate, string $multiDnRules): string
    {
        if ($dnStrategy === 'preserve_ldif_dn' && isset($row['dn'])) {
            return (string) $row['dn'];
        }

        if ($dnStrategy === 'multi_rules') {
            $type = (string) ($row['type'] ?? $row['entry_type'] ?? '');

            foreach ($this->parseMultiDnRules($multiDnRules) as $ruleType => $template) {
                if ($type !== '' && strtolower($type) === strtolower($ruleType)) {
                    return $this->replacePlaceholders($template, $row + ['base_dn' => $baseDn]);
                }
            }
        }

        if ($dnStrategy === 'dn_template' && filled($dnTemplate)) {
            return $this->replacePlaceholders((string) $dnTemplate, $row + ['base_dn' => $baseDn]);
        }

        if (filled($dnTemplate)) {
            return $this->replacePlaceholders((string) $dnTemplate, $row + ['base_dn' => $baseDn]);
        }

        $identifier = (string) ($row[$identifierAttribute] ?? '');

        if ($identifier === '') {
            return '';
        }

        $parent = filled($targetParentDn)
            ? $this->replacePlaceholders((string) $targetParentDn, $row + ['base_dn' => $baseDn])
            : $baseDn;

        return $identifierAttribute.'='.$identifier.','.$parent;
    }

    private function mapPayload(array $row, array $attributeMapping, array $defaultValues, array $excludedAttributes): array
    {
        $mapped = [];

        foreach ($defaultValues as $attribute => $value) {
            if ($attribute === '' || in_array($attribute, $excludedAttributes, true)) {
                continue;
            }

            $mapped[$attribute] = $value;
        }

        foreach ($row as $source => $value) {
            if (in_array($source, ['dn', 'action', 'type', 'entry_type'], true)) {
                continue;
            }

            $attribute = $attributeMapping[$source] ?? $source;

            if (in_array($attribute, $excludedAttributes, true)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $mapped[$attribute] = $value;
        }

        return $mapped;
    }

    private function buildLdifBlock(string $dn, array $row, string $operation, array $objectClasses, array $excludedAttributes): string
    {
        $lines = [
            '# Operation: '.$operation,
            'dn: '.$dn,
        ];

        if ($operation === 'delete') {
            $lines[] = 'changetype: delete';

            return implode("\n", $lines);
        }

        if ($operation === 'update') {
            $lines[] = 'changetype: modify';

            foreach ($row as $key => $value) {
                if ($key === 'dn' || in_array($key, $excludedAttributes, true)) {
                    continue;
                }

                $lines[] = 'replace: '.$key;
                $lines[] = $key.': '.$value;
                $lines[] = '-';
            }

            return implode("\n", $lines);
        }

        foreach ($objectClasses as $objectClass) {
            if ($objectClass !== '') {
                $lines[] = 'objectClass: '.$objectClass;
            }
        }

        foreach ($row as $attribute => $value) {
            if ($attribute === 'objectClass' || in_array($attribute, $excludedAttributes, true)) {
                continue;
            }

            foreach ($this->splitValue((string) $value) as $item) {
                if ($item !== '') {
                    $lines[] = $attribute.': '.$item;
                }
            }
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    private function resolveObjectClassesForRow(array $row, array $templateObjectClasses): array
    {
        $fromRow = $row['objectClass'] ?? $row['objectclass'] ?? null;

        if (is_array($fromRow)) {
            return array_values(array_unique(array_filter(array_map('trim', $fromRow))));
        }

        if (is_string($fromRow) && trim($fromRow) !== '') {
            return $this->splitValue($fromRow);
        }

        return array_values(array_unique(array_filter($templateObjectClasses)));
    }

    private function loadObjectClassSchema(int $ldapConnectionId): array
    {
        if ($ldapConnectionId <= 0 || ! Schema::hasTable('ldap_schema_entries')) {
            return [];
        }

        $columns = Schema::getColumnListing('ldap_schema_entries');

        if (! in_array('schema_type', $columns, true)) {
            return [];
        }

        $rows = DB::table('ldap_schema_entries')
            ->where('ldap_connection_id', $ldapConnectionId)
            ->where('schema_type', 'object_class')
            ->get();

        $schema = [];

        foreach ($rows as $row) {
            $names = $this->decodeJsonField($row->names ?? null);

            if ($names === []) {
                $names = array_values(array_filter([
                    $row->primary_name ?? null,
                    $row->name ?? null,
                    $row->display_name ?? null,
                ]));
            }

            $entry = [
                'oid' => $row->oid ?? null,
                'primary_name' => $row->primary_name ?? $row->name ?? null,
                'name' => $row->name ?? $row->primary_name ?? null,
                'names' => $names,
                'kind' => strtolower((string) ($row->kind ?? '')),
                'superior' => $row->superior ?? null,
                'must_attributes' => $this->decodeJsonField($row->must_attributes ?? null),
                'may_attributes' => $this->decodeJsonField($row->may_attributes ?? null),
            ];

            foreach ($names as $name) {
                $schema[strtolower((string) $name)] = $entry;
            }

            if ($entry['primary_name']) {
                $schema[strtolower((string) $entry['primary_name'])] = $entry;
            }

            if ($entry['name']) {
                $schema[strtolower((string) $entry['name'])] = $entry;
            }
        }

        return $schema;
    }

    private function validateObjectClassesAgainstSchema(array $objectClasses, array $schemaObjects): array
    {
        $warnings = [];
        $errors = [];
        $must = [];
        $may = [];
        $structural = [];
        $auxiliary = [];

        if ($schemaObjects === []) {
            return [
                'errors' => [],
                'warnings' => ['Schema-aware validation skipped because schema entries are not available for this LDAP connection.'],
                'must_attributes' => [],
                'may_attributes' => [],
                'structural_classes' => [],
                'auxiliary_classes' => [],
            ];
        }

        foreach ($objectClasses as $class) {
            $key = strtolower((string) $class);
            $schema = $schemaObjects[$key] ?? null;

            if (! $schema) {
                $warnings[] = 'ObjectClass not found in schema: '.$class;
                continue;
            }

            $must = array_merge($must, $schema['must_attributes'] ?? []);
            $may = array_merge($may, $schema['may_attributes'] ?? []);

            $kind = strtolower((string) ($schema['kind'] ?? ''));

            if ($kind === 'structural') {
                $structural[] = (string) ($schema['primary_name'] ?? $schema['name'] ?? $class);
            } elseif ($kind === 'auxiliary') {
                $auxiliary[] = (string) ($schema['primary_name'] ?? $schema['name'] ?? $class);
            }
        }

        $structural = array_values(array_unique(array_filter($structural)));
        $auxiliary = array_values(array_unique(array_filter($auxiliary)));

        $conflict = $this->detectStructuralConflict($structural, $schemaObjects);

        if ($conflict !== null) {
            $errors[] = $conflict;
        }

        return [
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'must_attributes' => array_values(array_unique(array_filter($must))),
            'may_attributes' => array_values(array_unique(array_filter($may))),
            'structural_classes' => $structural,
            'auxiliary_classes' => $auxiliary,
        ];
    }

    private function detectStructuralConflict(array $structuralClasses, array $schemaObjects): ?string
    {
        if (count($structuralClasses) <= 1) {
            return null;
        }

        foreach ($structuralClasses as $left) {
            foreach ($structuralClasses as $right) {
                if ($left === $right) {
                    continue;
                }

                if ($this->isAncestorOrSelf($left, $right, $schemaObjects) || $this->isAncestorOrSelf($right, $left, $schemaObjects)) {
                    continue;
                }

                return 'Structural objectClass conflict: '.$left.' and '.$right.' are not in the same inheritance chain.';
            }
        }

        return null;
    }

    private function isAncestorOrSelf(string $ancestor, string $child, array $schemaObjects): bool
    {
        $ancestorKey = strtolower($ancestor);
        $currentKey = strtolower($child);

        if ($ancestorKey === $currentKey) {
            return true;
        }

        $seen = [];

        while ($currentKey !== '') {
            if (isset($seen[$currentKey])) {
                return false;
            }

            $seen[$currentKey] = true;

            $current = $schemaObjects[$currentKey] ?? null;

            if (! $current) {
                return false;
            }

            $superior = strtolower((string) ($current['superior'] ?? ''));

            if ($superior === '') {
                return false;
            }

            if ($superior === $ancestorKey) {
                return true;
            }

            $currentKey = $superior;
        }

        return false;
    }

    private function validateAttributesAgainstSchema(array $payload, array $mustAttributes, array $mayAttributes, array $excludedAttributes): array
    {
        $warnings = [];

        $allowed = array_values(array_unique(array_filter(array_merge(
            $mustAttributes,
            $mayAttributes,
            ['objectClass'],
        ))));

        if ($allowed === []) {
            return [];
        }

        foreach (array_keys($payload) as $attribute) {
            if (in_array($attribute, $excludedAttributes, true)) {
                continue;
            }

            if (! in_array($attribute, $allowed, true)) {
                $warnings[] = 'Attribute may not be allowed by selected objectClass schema: '.$attribute;
            }
        }

        return array_values(array_unique($warnings));
    }

    private function parseMultiDnRules(string $raw): array
    {
        $rules = [];

        foreach ($this->lines($raw) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$type, $template] = explode(':', $line, 2);
            $type = trim($type);
            $template = trim($template);

            if ($type !== '' && $template !== '') {
                $rules[$type] = $template;
            }
        }

        return $rules;
    }

    private function replacePlaceholders(string $template, array $row): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', function (array $matches) use ($row): string {
            $key = $matches[1];

            return (string) ($row[$key] ?? '');
        }, $template) ?: $template;
    }

    private function lines(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($line) => trim((string) $line),
                $raw
            )));
        }

        return collect(preg_split("/\r?\n/", (string) $raw) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->values()
            ->all();
    }

    private function keyValueLines(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $items = [];

        foreach ($this->lines($raw) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $items[trim($key)] = trim($value);
        }

        return $items;
    }

    private function splitValue(string $value): array
    {
        if (str_contains($value, ';')) {
            return array_values(array_filter(array_map('trim', explode(';', $value))));
        }

        return [trim($value)];
    }

    private function decodeJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter($decoded));
            }

            return $this->lines($value);
        }

        return [];
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

    private function metadata(ImportBatch $batch): array
    {
        $metadata = $batch->metadata ?? [];

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function updateBatch(ImportBatch $batch, array $data): void
    {
        $columns = Schema::getColumnListing($batch->getTable());
        $clean = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $clean[$key] = $value;
        }

        if (in_array('updated_at', $columns, true)) {
            $clean['updated_at'] = now();
        }

        if ($clean !== []) {
            DB::table($batch->getTable())
                ->where('id', $batch->id)
                ->update($clean);
        }
    }

    private function replaceImportRows(ImportBatch $batch, array $rows): void
    {
        if (! Schema::hasTable('import_rows')) {
            return;
        }

        $columns = Schema::getColumnListing('import_rows');

        $columnTypes = collect(DB::select("
            select column_name, data_type, udt_name
            from information_schema.columns
            where table_schema = 'public'
            and table_name = 'import_rows'
        "))
            ->mapWithKeys(fn ($column) => [
                $column->column_name => strtolower((string) ($column->data_type ?: $column->udt_name)),
            ])
            ->all();

        $jsonColumns = collect($columnTypes)
            ->filter(fn ($type) => str_contains($type, 'json'))
            ->keys()
            ->all();

        DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->delete();

        foreach ($rows as $row) {
            $targetDn = (string) ($row['target_dn'] ?? '');
            $targetIdentifier = (string) ($row['target_identifier'] ?? '');
            $operation = (string) ($row['operation'] ?? $row['action_plan'] ?? 'skip');
            $actionPlan = (string) ($row['action_plan'] ?? $operation);

            $record = [
                'uuid' => (string) Str::uuid(),
                'import_batch_id' => $batch->id,

                'row_number' => $row['row_number'] ?? null,
                'row_index' => $row['row_number'] ?? null,
                'line_number' => $row['row_number'] ?? null,

                'status' => $row['status'] ?? null,

                'operation' => $operation,
                'action' => $operation,
                'plan' => $actionPlan,
                'action_plan' => $actionPlan,
                'planned_action' => $actionPlan,

                'dn' => $targetDn,
                'target_dn' => $this->normalizeImportDnValue((string) $targetDn),

                'identifier' => $targetIdentifier,
                'target_identifier' => $targetIdentifier,

                'message' => $row['message'] ?? null,
                'conflict_reason' => $row['conflict_reason'] ?? null,

                'errors' => $row['validation_errors'] ?? [],
                'validation_errors' => $row['validation_errors'] ?? [],
                'warnings' => $row['warnings'] ?? [],

                'raw_payload' => $row['raw_payload'] ?? [],
                'mapped_payload' => $row['mapped_payload'] ?? [],
                'payload' => $row['mapped_payload'] ?? [],
                'metadata' => $row,

                'preview_ldif' => $row['preview_ldif'] ?? null,
                'ldif_preview' => $row['preview_ldif'] ?? null,

                'payload_hash' => hash('sha256', json_encode($row['mapped_payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),

                'created_at' => now(),
                'updated_at' => now(),
            ];

            $clean = [];

            foreach ($record as $key => $value) {
                if (! in_array($key, $columns, true)) {
                    continue;
                }

                if (in_array($key, $jsonColumns, true)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $clean[$key] = $value;
            }

            if ($clean !== []) {
                DB::table('import_rows')->insert($clean);
            }
        }
    }

    private function buildDependencyGraphAfterPreview(ImportBatch $batch): void
    {
        try {
            app(LdapImportDependencyGraphService::class)->buildForBatch((int) $batch->getKey());
        } catch (\Throwable $exception) {
            /*
             * Dependency graph failure must not break preview.
             * Preview tetap valid, tetapi batch message diberi warning.
             */
            $this->updateBatch($batch, [
                'message' => trim((string) ($batch->message ?? '')).' Dependency graph warning: '.$exception->getMessage(),
            ]);
        }
    }



    private function normalizeImportDnValue(string $dn): string
    {
        $dn = trim($dn);

        if ($dn === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map(
            fn ($part) => trim((string) $part),
            explode(',', $dn)
        ), fn ($part) => $part !== ''));

        foreach ($parts as $index => $part) {
            $lower = strtolower($part);

            if (str_starts_with($lower, 'ou=ou=')) {
                $parts[$index] = 'ou='.substr($part, 6);
            }

            if (str_starts_with($lower, 'cn=cn=')) {
                $parts[$index] = 'cn='.substr($part, 6);
            }

            if (str_starts_with($lower, 'uid=uid=')) {
                $parts[$index] = 'uid='.substr($part, 8);
            }
        }

        $changed = true;

        while ($changed) {
            $changed = false;
            $count = count($parts);

            for ($length = min(8, intdiv($count, 2)); $length >= 2; $length--) {
                $tail = array_slice($parts, -$length);
                $beforeTail = array_slice($parts, -($length * 2), $length);

                if (count($tail) !== $length || count($beforeTail) !== $length) {
                    continue;
                }

                $tailLower = array_map('strtolower', $tail);
                $beforeLower = array_map('strtolower', $beforeTail);

                $allDc = collect($tailLower)->every(fn ($part) => str_starts_with($part, 'dc='));

                if ($allDc && $tailLower === $beforeLower) {
                    array_splice($parts, -$length);
                    $changed = true;
                    break;
                }
            }
        }

        return implode(',', $parts);
    }


}
