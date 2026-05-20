<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SmartImportTemplateResolver
{
    public function buildMetadata(array $input): array
    {
        $ldapConnectionId = (int) ($input['ldap_connection_id'] ?? 0);
        $operationMode = (string) ($input['operation_mode'] ?? 'create');
        $fileFormat = (string) ($input['file_format'] ?? 'csv');
        $objectType = (string) ($input['object_type'] ?? 'user');

        $connection = $this->ldapConnection($ldapConnectionId);

        $baseDn = $this->firstFilled([
            $input['base_dn'] ?? null,
            $connection['base_dn'] ?? null,
            $connection['root_dn'] ?? null,
            $connection['default_base_dn'] ?? null,
            'dc=example,dc=local',
        ]);

        $schema = $this->loadSchema($ldapConnectionId);

        $resolved = $this->resolveObjectType(
            objectType: $objectType,
            input: $input,
            schema: $schema,
        );

        $objectClasses = $resolved['object_classes'];
        $rdnAttribute = $this->firstFilled([
            $input['rdn_attribute'] ?? null,
            $resolved['rdn_attribute'],
        ]);

        $parentDn = $this->firstFilled([
            $input['parent_dn'] ?? null,
            $input['target_parent_dn'] ?? null,
            $resolved['parent_dn'],
        ]);

        $parentDn = $this->replaceBaseDn((string) $parentDn, (string) $baseDn);

        $dnTemplate = $this->firstFilled([
            $input['dn_template'] ?? null,
            $rdnAttribute.'={'.$rdnAttribute.'},'.$parentDn,
        ]);

        $schemaRules = $this->resolveSchemaRules($objectClasses, $schema);

        $manualRequired = $this->lines($input['required_attributes'] ?? '');
        $manualOptional = $this->lines($input['optional_attributes'] ?? '');

        $requiredAttributes = array_values(array_unique(array_filter(array_merge(
            [$rdnAttribute],
            $resolved['required_attributes'],
            $schemaRules['must_attributes'],
            $manualRequired,
        ))));

        $optionalAttributes = array_values(array_unique(array_filter(array_merge(
            $resolved['recommended_attributes'],
            $schemaRules['may_attributes'],
            $manualOptional,
        ))));

        $optionalAttributes = array_values(array_filter(
            $optionalAttributes,
            fn ($attribute) => ! in_array($attribute, $requiredAttributes, true)
        ));

        $attributeMapping = $this->defaultAttributeMapping($requiredAttributes, $optionalAttributes);

        $sampleRows = (int) ($input['sample_rows'] ?? 3);

        if ($sampleRows <= 0) {
            $sampleRows = 3;
        }

        $allowDestructive = (bool) ($input['allow_destructive_operation'] ?? false);

        return [
            'smart_template_version' => 1,
            'generated_by' => 'SmartImportTemplateResolver',
            'generated_at' => now()->toDateTimeString(),

            'ldap_connection_id' => $ldapConnectionId,
            'ldap_connection_name' => $connection['name'] ?? null,

            'operation_mode' => $operationMode,
            'file_format' => $fileFormat,
            'object_type' => $objectType,

            'dn_rules' => [
                'base_dn' => $baseDn,
                'dn_strategy' => 'dn_template',
                'target_parent_dn' => $parentDn,
                'rdn_attribute' => $rdnAttribute,
                'identifier_attribute' => $rdnAttribute,
                'dn_template' => $dnTemplate,
                'multi_dn_rules' => $this->defaultMultiDnRules((string) $baseDn),
            ],

            'object_rules' => [
                'object_type' => $objectType,
                'object_classes' => $objectClasses,
                'structural_classes' => $schemaRules['structural_classes'],
                'auxiliary_classes' => $schemaRules['auxiliary_classes'],
                'required_attributes' => $requiredAttributes,
                'optional_attributes' => $optionalAttributes,
                'attribute_mapping' => $attributeMapping,
                'default_values' => $this->defaultValues($objectClasses),
                'multi_value_separator' => ';',
                'schema_warnings' => $schemaRules['warnings'],
                'schema_errors' => $schemaRules['errors'],
            ],

            'safety_rules' => [
                'if_target_exists' => (string) ($input['if_target_exists'] ?? 'skip'),
                'allow_destructive_operation' => $allowDestructive,
                'safe_mode' => true,
                'preview_required' => true,
                'sample_rows' => $sampleRows,
                'excluded_attributes' => [
                    'userPassword',
                    'entryUUID',
                    'entryCSN',
                    'createTimestamp',
                    'creatorsName',
                    'modifyTimestamp',
                    'modifiersName',
                ],
            ],

            'ui' => [
                'simple_mode' => true,
                'advanced_available' => true,
            ],
        ];
    }

    public function generateTemplateContent(array $metadata, string $format): string
    {
        $format = strtolower($format);

        return match ($format) {
            'ldif' => $this->generateLdif($metadata),
            'json' => $this->generateJson($metadata),
            default => $this->generateCsv($metadata),
        };
    }

    public function storeTemplateFile(object $record): string
    {
        $metadata = $this->recordMetadata($record);

        if ($metadata === []) {
            $metadata = $this->buildMetadata([
                'ldap_connection_id' => $record->ldap_connection_id ?? null,
                'operation_mode' => $record->template_purpose ?? 'create',
                'file_format' => $record->file_format ?? 'csv',
                'object_type' => $record->entry_type ?? 'user',
                'base_dn' => $record->base_dn ?? null,
                'rdn_attribute' => $record->identifier_attribute ?? null,
                'sample_rows' => $record->sample_rows ?? 3,
            ]);
        }

        $format = strtolower((string) ($metadata['file_format'] ?? $record->file_format ?? 'csv'));
        $content = $this->generateTemplateContent($metadata, $format);

        $safeName = Str::of((string) ($record->name ?? 'ldap-import-template'))
            ->slug()
            ->toString();

        $extension = match ($format) {
            'ldif' => 'ldif',
            'json' => 'json',
            default => 'csv',
        };

        $path = 'imports/templates/'.$safeName.'_'.now()->format('Ymd_His').'.'.$extension;

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    public function generateCsv(array $metadata): string
    {
        $rows = $this->sampleRows($metadata);
        $headers = $this->headers($metadata);

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn ($header) => $row[$header] ?? '',
                $headers
            ));
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    public function generateJson(array $metadata): string
    {
        return json_encode($this->sampleRows($metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: "[]";
    }

    public function generateLdif(array $metadata): string
    {
        $rows = $this->sampleRows($metadata);
        $blocks = [];

        foreach ($rows as $row) {
            $lines = [];

            $lines[] = 'dn: '.$row['dn'];

            foreach ($this->objectClasses($metadata) as $objectClass) {
                $lines[] = 'objectClass: '.$objectClass;
            }

            foreach ($row as $attribute => $value) {
                if (in_array($attribute, ['action', 'dn', 'objectClass'], true)) {
                    continue;
                }

                if ($value === '') {
                    continue;
                }

                foreach ($this->splitMultiValue((string) $value, $this->multiValueSeparator($metadata)) as $item) {
                    $lines[] = $attribute.': '.$item;
                }
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks)."\n";
    }

    public function sampleRows(array $metadata): array
    {
        $rows = [];

        $operation = $this->operationMode($metadata);
        $sampleRows = (int) ($metadata['safety_rules']['sample_rows'] ?? 3);

        if ($sampleRows <= 0) {
            $sampleRows = 3;
        }

        $rdn = $this->rdnAttribute($metadata);
        $baseDn = $this->baseDn($metadata);
        $dnTemplate = $this->dnTemplate($metadata);
        $objectClassValue = implode($this->multiValueSeparator($metadata), $this->objectClasses($metadata));

        $attributes = $this->allDataAttributes($metadata);

        for ($i = 1; $i <= $sampleRows; $i++) {
            $number = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $identifier = $this->sampleIdentifier($rdn, $number, $metadata);

            $placeholderData = [
                'base_dn' => $baseDn,
                'uid' => $rdn === 'uid' ? $identifier : 'test-user-'.$number,
                'cn' => $rdn === 'cn' ? $identifier : 'Test Name '.$number,
                'ou' => $rdn === 'ou' ? $identifier : 'test-ou-'.$number,
                $rdn => $identifier,
            ];

            $dn = $this->replacePlaceholders($dnTemplate, $placeholderData);

            $row = [
                'action' => $operation,
                'dn' => $dn,
            ];

            foreach ($attributes as $attribute) {
                $row[$attribute] = $this->sampleValue(
                    attribute: $attribute,
                    number: $number,
                    baseDn: $baseDn,
                    rdnAttribute: $rdn,
                    identifierValue: $identifier,
                    metadata: $metadata,
                );
            }

            $row['objectClass'] = $objectClassValue;

            $rows[] = $row;
        }

        return $rows;
    }

    public function headers(array $metadata): array
    {
        return array_values(array_unique(array_merge(
            ['action', 'dn'],
            $this->allDataAttributes($metadata),
            ['objectClass'],
        )));
    }

    private function allDataAttributes(array $metadata): array
    {
        $required = $metadata['object_rules']['required_attributes'] ?? [];
        $optional = $metadata['object_rules']['optional_attributes'] ?? [];

        /*
         * IMPORTANT:
         * Jangan otomatis memasukkan semua MAY attributes dari schema ke CSV.
         * Banyak MAY attribute punya syntax khusus, misalnya seeAlso dan owner bertipe DN.
         * Default template harus anti-error: required + optional aman saja.
         */
        $attributes = array_values(array_unique(array_filter(array_merge(
            [$this->rdnAttribute($metadata)],
            $required,
            $this->recommendedOptionalSubset($optional, $metadata),
        ))));

        return array_values(array_filter(
            $attributes,
            fn ($attribute) => ! in_array($attribute, ['action', 'dn', 'objectClass', 'objectclass'], true)
        ));
    }


    private function recommendedOptionalSubset(array $optional, array $metadata = []): array
    {
        $objectType = strtolower((string) ($metadata['object_type'] ?? $metadata['object_rules']['object_type'] ?? 'user'));

        /*
         * Safe defaults only.
         * Advanced attributes tetap bisa ditambahkan manual, tapi tidak otomatis masuk template.
         */
        $safeByType = match ($objectType) {
            'group', 'groupofnames' => ['description'],
            'ou', 'organizationalunit' => ['description'],
            'device' => ['description'],
            'service', 'service_account' => ['description'],
            default => ['mail', 'givenName', 'displayName', 'description'],
        };

        $selected = [];

        foreach ($safeByType as $attribute) {
            if (in_array($attribute, $optional, true)) {
                $selected[] = $attribute;
            }
        }

        return $selected;
    }


    private function resolveObjectType(string $objectType, array $input, array $schema): array
    {
        $customObjectClasses = $this->lines($input['object_classes'] ?? '');
        $auxiliary = $this->lines($input['auxiliary_object_classes'] ?? '');

        $objectType = strtolower($objectType);

        $defaults = match ($objectType) {
            'group', 'groupofnames' => [
                'object_classes' => ['top', 'groupOfNames'],
                'rdn_attribute' => 'cn',
                'parent_dn' => 'ou=groups,{base_dn}',
                'required_attributes' => ['cn', 'member'],
                'recommended_attributes' => ['description'],
            ],
            'ou', 'organizationalunit' => [
                'object_classes' => ['top', 'organizationalUnit'],
                'rdn_attribute' => 'ou',
                'parent_dn' => '{base_dn}',
                'required_attributes' => ['ou'],
                'recommended_attributes' => ['description'],
            ],
            'device' => [
                'object_classes' => ['top', 'device'],
                'rdn_attribute' => 'cn',
                'parent_dn' => 'ou=devices,{base_dn}',
                'required_attributes' => ['cn'],
                'recommended_attributes' => ['description', 'serialNumber'],
            ],
            'service', 'service_account' => [
                'object_classes' => ['top', 'account', 'simpleSecurityObject'],
                'rdn_attribute' => 'uid',
                'parent_dn' => 'ou=services,{base_dn}',
                'required_attributes' => ['uid'],
                'recommended_attributes' => ['description'],
            ],
            'custom' => [
                'object_classes' => $customObjectClasses !== [] ? $customObjectClasses : ['top'],
                'rdn_attribute' => (string) ($input['rdn_attribute'] ?? 'cn'),
                'parent_dn' => (string) ($input['parent_dn'] ?? '{base_dn}'),
                'required_attributes' => [],
                'recommended_attributes' => ['description'],
            ],
            default => [
                'object_classes' => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
                'rdn_attribute' => 'uid',
                'parent_dn' => 'ou=people,{base_dn}',
                'required_attributes' => ['uid', 'cn', 'sn'],
                'recommended_attributes' => ['mail', 'givenName', 'displayName', 'description'],
            ],
        };

        $objectClasses = array_values(array_unique(array_filter(array_merge(
            $defaults['object_classes'],
            $auxiliary,
        ))));

        return [
            'object_classes' => $objectClasses,
            'rdn_attribute' => $defaults['rdn_attribute'],
            'parent_dn' => $defaults['parent_dn'],
            'required_attributes' => $defaults['required_attributes'],
            'recommended_attributes' => $defaults['recommended_attributes'],
        ];
    }

    private function resolveSchemaRules(array $objectClasses, array $schema): array
    {
        $must = [];
        $may = [];
        $warnings = [];
        $errors = [];
        $structural = [];
        $auxiliary = [];

        $fallbackMust = [
            'person' => ['sn', 'cn'],
            'organizationalperson' => ['sn', 'cn'],
            'inetorgperson' => ['sn', 'cn'],
            'groupofnames' => ['cn', 'member'],
            'groupofuniquenames' => ['cn', 'uniqueMember'],
            'organizationalunit' => ['ou'],
            'device' => ['cn'],
            'account' => ['uid'],
            'simplesecurityobject' => ['userPassword'],
        ];

        $fallbackMay = [
            'inetorgperson' => ['mail', 'givenName', 'displayName', 'description', 'telephoneNumber'],
            'groupofnames' => ['description', 'owner', 'businessCategory'],
            'organizationalunit' => ['description'],
            'device' => ['description', 'serialNumber', 'seeAlso', 'owner'],
            'account' => ['description'],
        ];

        foreach ($objectClasses as $class) {
            $key = strtolower((string) $class);
            $entry = $schema[$key] ?? null;

            if ($entry) {
                $must = array_merge($must, $entry['must_attributes'] ?? []);
                $may = array_merge($may, $entry['may_attributes'] ?? []);

                $kind = strtolower((string) ($entry['kind'] ?? ''));

                if ($kind === 'structural') {
                    $structural[] = $entry['primary_name'] ?? $entry['name'] ?? $class;
                }

                if ($kind === 'auxiliary') {
                    $auxiliary[] = $entry['primary_name'] ?? $entry['name'] ?? $class;
                }
            } else {
                if (isset($fallbackMust[$key])) {
                    $must = array_merge($must, $fallbackMust[$key]);
                }

                if (isset($fallbackMay[$key])) {
                    $may = array_merge($may, $fallbackMay[$key]);
                }

                if ($key !== 'top') {
                    $warnings[] = 'ObjectClass not found in cached schema, fallback rules used: '.$class;
                }
            }
        }

        $structural = array_values(array_unique(array_filter($structural)));

        if (count($structural) > 1) {
            $errors[] = 'Potential structural objectClass conflict: '.implode(', ', $structural);
        }

        return [
            'must_attributes' => array_values(array_unique(array_filter($must))),
            'may_attributes' => array_values(array_unique(array_filter($may))),
            'structural_classes' => $structural,
            'auxiliary_classes' => array_values(array_unique(array_filter($auxiliary))),
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function loadSchema(int $ldapConnectionId): array
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
            $names = $this->decodeList($row->names ?? null);

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
                'must_attributes' => $this->decodeList($row->must_attributes ?? null),
                'may_attributes' => $this->decodeList($row->may_attributes ?? null),
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

    private function ldapConnection(int $id): array
    {
        if ($id <= 0 || ! Schema::hasTable('ldap_connections')) {
            return [];
        }

        $row = DB::table('ldap_connections')->where('id', $id)->first();

        return $row ? (array) $row : [];
    }

    private function defaultAttributeMapping(array $required, array $optional): array
    {
        $mapping = [];

        foreach (array_values(array_unique(array_merge($required, $optional))) as $attribute) {
            $mapping[$attribute] = $attribute;
        }

        return $mapping;
    }

    private function defaultValues(array $objectClasses): array
    {
        return [
            'objectClass' => implode(';', $objectClasses),
        ];
    }

    private function defaultMultiDnRules(string $baseDn): string
    {
        return implode("\n", [
            'user: uid={uid},ou=people,'.$baseDn,
            'group: cn={cn},ou=groups,'.$baseDn,
            'ou: ou={ou},'.$baseDn,
            'device: cn={cn},ou=devices,'.$baseDn,
            'service: uid={uid},ou=services,'.$baseDn,
        ]);
    }

    private function sampleIdentifier(string $rdn, string $number, array $metadata): string
    {
        $objectType = strtolower((string) ($metadata['object_type'] ?? 'user'));

        if ($rdn === 'cn' && str_contains($objectType, 'group')) {
            return 'test-group-'.$number;
        }

        if ($rdn === 'ou') {
            return 'test-ou-'.$number;
        }

        if ($rdn === 'cn') {
            return 'test-cn-'.$number;
        }

        return 'test-user-'.$number;
    }

    private function sampleValue(string $attribute, string $number, string $baseDn, string $rdnAttribute, string $identifierValue, array $metadata): string
    {
        if ($attribute === $rdnAttribute) {
            return $identifierValue;
        }

        return match ($attribute) {
            'uid' => 'test-user-'.$number,
            'cn' => $rdnAttribute === 'cn' ? $identifierValue : 'Test User '.$number,
            'sn' => 'User '.$number,
            'givenName' => 'Test',
            'displayName' => 'Test User '.$number,
            'mail' => 'test.user'.$number.'@example.local',
            'member' => 'uid=test-user-001,ou=people,'.$baseDn,
            'uniqueMember' => 'uid=test-user-001,ou=people,'.$baseDn,
            'owner' => 'uid=test-user-001,ou=people,'.$baseDn,
            'seeAlso' => 'uid=test-user-001,ou=people,'.$baseDn,
            'ou' => $rdnAttribute === 'ou' ? $identifierValue : 'test-ou-'.$number,
            'description' => 'Generated from Smart LDAP Import Template',
            'userPassword' => 'ChangeMe123!',
            default => $attribute.'-'.$number,
        };
    }

    private function operationMode(array $metadata): string
    {
        return match ((string) ($metadata['operation_mode'] ?? 'create')) {
            'update' => 'update',
            'upsert' => 'upsert',
            'delete' => 'delete',
            default => 'create',
        };
    }

    private function baseDn(array $metadata): string
    {
        return (string) ($metadata['dn_rules']['base_dn'] ?? 'dc=example,dc=local');
    }

    private function rdnAttribute(array $metadata): string
    {
        return (string) ($metadata['dn_rules']['rdn_attribute'] ?? 'uid');
    }

    private function dnTemplate(array $metadata): string
    {
        return (string) ($metadata['dn_rules']['dn_template'] ?? 'uid={uid},{base_dn}');
    }

    private function objectClasses(array $metadata): array
    {
        return $this->lines($metadata['object_rules']['object_classes'] ?? ['top']);
    }

    private function multiValueSeparator(array $metadata): string
    {
        return (string) ($metadata['object_rules']['multi_value_separator'] ?? ';');
    }

    private function recordMetadata(object $record): array
    {
        $metadata = $record->metadata ?? [];

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function replaceBaseDn(string $value, string $baseDn): string
    {
        return str_replace('{base_dn}', $baseDn, $value);
    }

    private function replacePlaceholders(string $template, array $data): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', function (array $matches) use ($data): string {
            return (string) ($data[$matches[1]] ?? '');
        }, $template) ?: $template;
    }

    private function splitMultiValue(string $value, string $separator): array
    {
        if ($separator !== '' && str_contains($value, $separator)) {
            return array_values(array_filter(array_map('trim', explode($separator, $value))));
        }

        return [trim($value)];
    }

    private function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            )));
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter(array_map(
                    fn ($item) => trim((string) $item),
                    $decoded
                )));
            }
        }

        return array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            preg_split('/\r?\n/', (string) $value) ?: []
        )));
    }

    private function keyValueLines(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $items = [];

        foreach ($this->lines($value) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $line, 2);
            $items[trim($key)] = trim($val);
        }

        return $items;
    }

    private function decodeList(mixed $value): array
    {
        return $this->lines($value);
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
