<?php

namespace App\Services\Operations;

use App\Models\Directory\LdapConnection;
use App\Models\Operations\LdapCrudOperation;
use App\Services\Ldap\LdapSchemaDropdownService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LdapCrudOperationService
{
    public function preview(LdapCrudOperation $operation): array
    {
        $baseDn = $this->effectiveBaseDn($operation);
        $filter = trim((string) ($operation->ldap_filter ?: '(objectClass=*)'));

        if ($baseDn === '') {
            return $this->fail('Base DN / Custom Target DN wajib diisi.');
        }

        if ($filter === '') {
            $filter = '(objectClass=*)';
        }

        $schemaCheck = $this->validateObjectClassRules($operation);

        if (! ($schemaCheck['ok'] ?? false)) {
            return $schemaCheck;
        }

        $ldap = $this->connectFromOperation($operation);

        if (! $ldap) {
            return $this->fail('Gagal connect/bind ke LDAP connection yang dipilih.');
        }

        $entries = $this->searchEntries($ldap, $operation, $baseDn, $filter);

        $previewEntries = [];

        foreach ($entries as $entry) {
            $previewEntries[] = $this->buildPreviewEntry($operation, $entry);
        }

        $result = [
            'ok' => true,
            'message' => 'Preview berhasil dari LDAP asli. Total matched: ' . count($previewEntries),
            'entry_count' => count($previewEntries),
            'entries' => $previewEntries,
            'meta' => [
                'ldap_connection_id' => $operation->ldap_connection_id,
                'target_mode' => $operation->target_mode,
                'base_dn' => $baseDn,
                'search_scope' => $operation->search_scope,
                'ldap_filter' => $filter,
                'operation_kind' => $operation->operation_kind,
                'objectclass_name' => $operation->objectclass_name,
                'objectclass_must_values' => $operation->objectclass_must_values,
            ],
        ];

        $operation->forceFill([
            'status' => 'previewed',
            'preview_result' => $result,
            'previewed_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'preview', 'success', $result['message']);
        $this->writeOperationLog($operation, 'preview', 'success', $result['message']);

        return $result;
    }

    public function apply(LdapCrudOperation $operation): array
    {
        $preview = $operation->preview_result;

        if (! is_array($preview) || empty($preview['entries'])) {
            return $this->fail('Preview belum ada. Generate Preview dulu.');
        }

        $ldap = $this->connectFromOperation($operation);

        if (! $ldap) {
            return $this->fail('Gagal connect/bind ke LDAP connection yang dipilih.');
        }

        $results = [];
        $rollbackItems = [];

        foreach ($preview['entries'] as $entry) {
            $dn = (string) ($entry['dn'] ?? '');

            if ($dn === '') {
                continue;
            }

            if (($entry['status'] ?? null) !== 'planned') {
                $row = [
                    'dn' => $dn,
                    'status' => 'skipped',
                    'reason' => $entry['reason'] ?? 'Entry tidak planned.',
                ];

                $results[] = $row;
                $this->writeItemLog($operation, $row);
                continue;
            }

            try {
                $row = $this->applyEntry($ldap, $operation, $dn);
            } catch (Throwable $e) {
                $row = [
                    'dn' => $dn,
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ];
            }

            $results[] = $row;
            $this->writeItemLog($operation, $row);

            if (($row['status'] ?? null) === 'applied') {
                $rollbackItems[] = [
                    'dn' => $dn,
                    'operation_kind' => $operation->operation_kind,
                    'objectclass_name' => $operation->objectclass_name,
                    'added_attributes' => $row['added_attributes'] ?? [],
                    'added_objectclass' => $row['added_objectclass'] ?? false,
                ];
            }
        }

        $success = collect($results)->where('status', 'applied')->count();
        $failed = collect($results)->where('status', 'failed')->count();
        $skipped = collect($results)->where('status', 'skipped')->count();

        $result = [
            'ok' => $failed === 0,
            'message' => "Apply selesai. Success: {$success}, skipped: {$skipped}, failed: {$failed}.",
            'results' => $results,
        ];

        $operation->forceFill([
            'status' => $failed === 0 ? 'applied' : 'partial_failed',
            'execution_result' => $result,
            'rollback_payload' => [
                'generated_at' => now()->toDateTimeString(),
                'items' => $rollbackItems,
            ],
            'executed_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'apply', $failed === 0 ? 'success' : 'partial_failed', $result['message']);
        $this->writeOperationLog($operation, 'apply', $failed === 0 ? 'success' : 'partial_failed', $result['message']);

        return $result;
    }

    public function rollback(LdapCrudOperation $operation): array
    {
        $payload = $operation->rollback_payload;

        if (! is_array($payload) || empty($payload['items'])) {
            return $this->fail('Rollback payload belum tersedia.');
        }

        $ldap = $this->connectFromOperation($operation);

        if (! $ldap) {
            return $this->fail('Gagal connect/bind ke LDAP connection yang dipilih.');
        }

        $results = [];

        foreach ($payload['items'] as $item) {
            $dn = (string) ($item['dn'] ?? '');

            if ($dn === '') {
                continue;
            }

            try {
                $modDelete = [];

                foreach (($item['added_attributes'] ?? []) as $attribute => $value) {
                    if (is_array($value)) {
                        $modDelete[$attribute] = $value;
                    } else {
                        $modDelete[$attribute] = [$value];
                    }
                }

                if (! empty($modDelete)) {
                    @ldap_mod_del($ldap, $dn, $modDelete);
                }

                if (! empty($item['added_objectclass']) && ! empty($item['objectclass_name'])) {
                    @ldap_mod_del($ldap, $dn, [
                        'objectClass' => [(string) $item['objectclass_name']],
                    ]);
                }

                $row = [
                    'dn' => $dn,
                    'status' => 'rollback_success',
                    'reason' => 'Rollback LDAP berhasil.',
                ];
            } catch (Throwable $e) {
                $row = [
                    'dn' => $dn,
                    'status' => 'rollback_failed',
                    'reason' => $e->getMessage(),
                ];
            }

            $results[] = $row;
        }

        $failed = collect($results)->where('status', 'rollback_failed')->count();

        $result = [
            'ok' => $failed === 0,
            'message' => $failed === 0 ? 'Rollback selesai.' : 'Rollback selesai dengan sebagian gagal.',
            'results' => $results,
        ];

        $operation->forceFill([
            'status' => $failed === 0 ? 'rolled_back' : 'rollback_partial_failed',
            'rollback_result' => $result,
            'rolled_back_at' => now(),
        ])->save();

        $this->writeAudit($operation, 'rollback', $failed === 0 ? 'success' : 'partial_failed', $result['message']);
        $this->writeOperationLog($operation, 'rollback', $failed === 0 ? 'success' : 'partial_failed', $result['message']);

        return $result;
    }

    private function applyEntry(mixed $ldap, LdapCrudOperation $operation, string $dn): array
    {
        return match ((string) $operation->operation_kind) {
            'add_objectclass' => $this->applyAddObjectClass($ldap, $operation, $dn),
            'delete_objectclass', 'remove_objectclass' => $this->applyDeleteObjectClass($ldap, $operation, $dn),
            default => [
                'dn' => $dn,
                'status' => 'skipped',
                'reason' => 'Real apply belum diaktifkan untuk operation type ini.',
            ],
        };
    }

    private function applyAddObjectClass(mixed $ldap, LdapCrudOperation $operation, string $dn): array
    {
        $objectClass = (string) $operation->objectclass_name;

        if ($objectClass === '') {
            return [
                'dn' => $dn,
                'status' => 'failed',
                'reason' => 'ObjectClass kosong.',
            ];
        }

        $current = $this->readEntry($ldap, $dn);
        $currentObjectClasses = array_map('strtolower', $current['objectclass'] ?? []);

        if (in_array(strtolower($objectClass), $currentObjectClasses, true)) {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'reason' => 'Entry sudah punya objectClass tersebut.',
            ];
        }

        $mustValues = is_array($operation->objectclass_must_values)
            ? $operation->objectclass_must_values
            : [];

        $modAdd = [
            'objectClass' => [$objectClass],
        ];

        $addedAttributes = [];

        foreach ($mustValues as $attribute => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $attributeLower = strtolower((string) $attribute);
            $currentHasAttribute = false;

            foreach (array_keys($current) as $existingAttribute) {
                if (strtolower($existingAttribute) === $attributeLower) {
                    $currentHasAttribute = true;
                    break;
                }
            }

            if (! $currentHasAttribute) {
                $modAdd[$attribute] = [(string) $value];
                $addedAttributes[$attribute] = (string) $value;
            }
        }

        $ok = @ldap_mod_add($ldap, $dn, $modAdd);

        if (! $ok) {
            return [
                'dn' => $dn,
                'status' => 'failed',
                'reason' => ldap_error($ldap),
                'mod_add' => $modAdd,
            ];
        }

        return [
            'dn' => $dn,
            'status' => 'applied',
            'reason' => 'ObjectClass berhasil ditambahkan ke LDAP asli.',
            'added_objectclass' => true,
            'added_attributes' => $addedAttributes,
            'mod_add' => $modAdd,
        ];
    }

    private function applyDeleteObjectClass(mixed $ldap, LdapCrudOperation $operation, string $dn): array
    {
        $objectClass = (string) $operation->objectclass_name;

        if ($objectClass === '') {
            return [
                'dn' => $dn,
                'status' => 'failed',
                'reason' => 'ObjectClass kosong.',
            ];
        }

        $current = $this->readEntry($ldap, $dn);
        $currentObjectClasses = array_map('strtolower', $current['objectclass'] ?? []);

        if (! in_array(strtolower($objectClass), $currentObjectClasses, true)) {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'reason' => 'Entry tidak punya objectClass tersebut.',
            ];
        }

        $schemaAttributes = app(\App\Services\Ldap\LdapSchemaDropdownService::class)
            ->attributeOptions($operation->ldap_connection_id, $objectClass);

        $relatedAttributes = [];

        foreach ($schemaAttributes as $attribute => $label) {
            $attributeName = is_string($attribute) ? $attribute : $label;
            $key = strtolower((string) $attributeName);

            if (isset($current[$key]) && $key !== 'objectclass') {
                $relatedAttributes[$attributeName] = $current[$key];
            }
        }

        if ((bool) ($operation->delete_related_objectclass_attributes ?? true)) {
            foreach ($relatedAttributes as $attribute => $values) {
                @ldap_mod_del($ldap, $dn, [
                    $attribute => $values,
                ]);
            }
        }

        $ok = @ldap_mod_del($ldap, $dn, [
            'objectClass' => [$objectClass],
        ]);

        if (! $ok) {
            return [
                'dn' => $dn,
                'status' => 'failed',
                'reason' => ldap_error($ldap),
                'related_attributes_attempted_delete' => $relatedAttributes,
            ];
        }

        return [
            'dn' => $dn,
            'status' => 'applied',
            'reason' => 'ObjectClass berhasil dihapus dari LDAP asli.',
            'deleted_objectclass' => $objectClass,
            'deleted_related_attributes' => $relatedAttributes,
            'rollback_restore' => [
                'objectClass' => [$objectClass],
                'attributes' => $relatedAttributes,
            ],
        ];
    }

    private function buildPreviewEntry(LdapCrudOperation $operation, array $entry): array
    {
        $dn = (string) ($entry['dn'] ?? '');
        $operationKind = (string) ($operation->operation_kind ?? '');

        return match ($operationKind) {
            'add_objectclass' => $this->previewAddObjectClass($operation, $entry, $dn),
            'delete_objectclass', 'remove_objectclass' => $this->previewDeleteObjectClass($operation, $entry, $dn),
            'add_attribute' => $this->previewAddAttribute($operation, $entry, $dn),
            'delete_attribute', 'remove_attribute' => $this->previewDeleteAttribute($operation, $entry, $dn),
            'move_ou', 'move_entry' => $this->previewMoveOu($operation, $entry, $dn),
            'delete_entry' => $this->previewDeleteEntry($operation, $entry, $dn),
            default => [
                'dn' => $dn,
                'status' => 'blocked',
                'planned_action' => $operationKind,
                'reason' => 'Operation type belum dikenal.',
            ],
        };
    }

    private function previewAddObjectClass(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        $objectClass = (string) $operation->objectclass_name;
        $currentObjectClasses = array_map('strtolower', $entry['objectclass'] ?? []);

        if ($objectClass === '') {
            return [
                'dn' => $dn,
                'status' => 'blocked',
                'planned_action' => 'add_objectclass',
                'reason' => 'ObjectClass kosong.',
            ];
        }

        if (in_array(strtolower($objectClass), $currentObjectClasses, true)) {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'planned_action' => 'add_objectclass',
                'reason' => 'Entry sudah punya objectClass ' . $objectClass,
                'current_objectclasses' => $entry['objectclass'] ?? [],
            ];
        }

        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'add_objectclass',
            'reason' => 'Akan menambahkan auxiliary objectClass ' . $objectClass,
            'current_objectclasses' => $entry['objectclass'] ?? [],
            'planned_changes' => [
                'add_objectClass' => $objectClass,
                'add_must_attributes_if_missing' => $operation->objectclass_must_values,
            ],
        ];
    }

    private function previewDeleteObjectClass(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        $objectClass = (string) $operation->objectclass_name;
        $currentObjectClasses = array_map('strtolower', $entry['objectclass'] ?? []);

        if ($objectClass === '') {
            return [
                'dn' => $dn,
                'status' => 'blocked',
                'planned_action' => 'delete_objectclass',
                'reason' => 'ObjectClass kosong.',
            ];
        }

        if (! in_array(strtolower($objectClass), $currentObjectClasses, true)) {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'planned_action' => 'delete_objectclass',
                'reason' => 'Entry tidak punya objectClass ' . $objectClass,
                'current_objectclasses' => $entry['objectclass'] ?? [],
            ];
        }

        $attributes = app(\App\Services\Ldap\LdapSchemaDropdownService::class)
            ->attributeOptions($operation->ldap_connection_id, $objectClass);

        $relatedAttributes = [];

        foreach ($attributes as $attribute => $label) {
            $attributeName = is_string($attribute) ? $attribute : $label;
            $key = strtolower((string) $attributeName);

            if (array_key_exists($key, $entry)) {
                $relatedAttributes[$attributeName] = $entry[$key];
            }
        }

        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'delete_objectclass',
            'reason' => 'Entry punya objectClass ' . $objectClass . '. Akan dihapus bersama related attributes jika aman.',
            'current_objectclasses' => $entry['objectclass'] ?? [],
            'before' => [
                'objectClass' => $entry['objectclass'] ?? [],
                'related_attributes' => $relatedAttributes,
            ],
            'planned_changes' => [
                'delete_objectClass' => $objectClass,
                'delete_related_attributes' => (bool) ($operation->delete_related_objectclass_attributes ?? true),
                'candidate_related_attributes' => array_keys($relatedAttributes),
            ],
            'rollback_plan' => [
                'restore_objectClass' => $objectClass,
                'restore_related_attributes' => $relatedAttributes,
            ],
        ];
    }

    private function previewAddAttribute(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        $attribute = (string) $operation->attribute_name;
        $value = (string) $operation->attribute_value;
        $behavior = (string) ($operation->existing_value_behavior ?? 'skip');

        if ($attribute === '') {
            return ['dn' => $dn, 'status' => 'blocked', 'planned_action' => 'add_attribute', 'reason' => 'Attribute kosong.'];
        }

        if ($value === '') {
            return ['dn' => $dn, 'status' => 'blocked', 'planned_action' => 'add_attribute', 'reason' => 'Attribute value kosong.'];
        }

        $key = strtolower($attribute);
        $existingValues = $entry[$key] ?? [];

        if (! empty($existingValues) && $behavior === 'skip') {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'planned_action' => 'add_attribute',
                'reason' => 'Attribute sudah ada dan behavior adalah skip.',
                'attribute' => $attribute,
                'existing_values' => $existingValues,
            ];
        }

        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'add_attribute',
            'reason' => 'Attribute akan ditambahkan/diproses.',
            'before' => [$attribute => $existingValues],
            'planned_changes' => [
                'attribute' => $attribute,
                'value' => $value,
                'behavior' => $behavior,
            ],
        ];
    }

    private function previewDeleteAttribute(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        $attribute = (string) $operation->attribute_name;

        if ($attribute === '') {
            return ['dn' => $dn, 'status' => 'blocked', 'planned_action' => 'delete_attribute', 'reason' => 'Attribute kosong.'];
        }

        $key = strtolower($attribute);
        $existingValues = $entry[$key] ?? [];

        if (empty($existingValues)) {
            return [
                'dn' => $dn,
                'status' => 'skipped',
                'planned_action' => 'delete_attribute',
                'reason' => 'Attribute tidak ada pada entry.',
                'attribute' => $attribute,
            ];
        }

        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'delete_attribute',
            'reason' => 'Attribute ada dan akan dihapus.',
            'before' => [$attribute => $existingValues],
            'planned_changes' => ['delete_attribute' => $attribute],
        ];
    }

    private function previewMoveOu(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        $targetOuDn = trim((string) ($operation->target_ou_dn ?? ''));

        if ($targetOuDn === '') {
            return ['dn' => $dn, 'status' => 'blocked', 'planned_action' => 'move_ou', 'reason' => 'Target OU DN kosong.'];
        }

        $rdn = $this->rdnFromDn($dn);
        $oldParent = $this->parentDnFromDn($dn);
        $newDn = $rdn . ',' . $targetOuDn;

        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'move_ou',
            'reason' => 'Entry akan dipindahkan ke parent DN baru tanpa rename RDN.',
            'before' => ['old_dn' => $dn, 'old_parent_dn' => $oldParent],
            'planned_changes' => ['rdn' => $rdn, 'new_parent_dn' => $targetOuDn, 'new_dn' => $newDn],
        ];
    }

    private function previewDeleteEntry(LdapCrudOperation $operation, array $entry, string $dn): array
    {
        return [
            'dn' => $dn,
            'status' => 'planned',
            'planned_action' => 'delete_entry',
            'reason' => 'Entry akan dihapus. Pastikan target benar.',
            'before' => [
                'dn' => $dn,
                'attributes_snapshot' => $entry,
            ],
            'warning' => 'Jika entry punya child, apply delete dapat gagal. Jangan hapus OU besar tanpa backup LDIF.',
        ];
    }

    private function rdnFromDn(string $dn): string
    {
        $parts = explode(',', $dn, 2);

        return trim($parts[0] ?? $dn);
    }

    private function parentDnFromDn(string $dn): string
    {
        $parts = explode(',', $dn, 2);

        return trim($parts[1] ?? '');
    }

    private function searchEntries(mixed $ldap, LdapCrudOperation $operation, string $baseDn, string $filter): array
    {
        $attributes = ['dn', 'objectClass', 'uid', 'cn', 'ou', 'mail'];

        $scope = strtolower((string) ($operation->search_scope ?? 'subtree'));

        $result = match ($scope) {
            'base' => @ldap_read($ldap, $baseDn, $filter, $attributes, 0, (int) ($operation->size_limit ?: 100)),
            'one', 'onelevel', 'one_level' => @ldap_list($ldap, $baseDn, $filter, $attributes, 0, (int) ($operation->size_limit ?: 100)),
            default => @ldap_search($ldap, $baseDn, $filter, $attributes, 0, (int) ($operation->size_limit ?: 100)),
        };

        if (! $result) {
            throw new \RuntimeException('LDAP search gagal: ' . ldap_error($ldap));
        }

        $entries = @ldap_get_entries($ldap, $result);
        $out = [];

        $count = (int) ($entries['count'] ?? 0);

        for ($i = 0; $i < $count; $i++) {
            $row = $entries[$i];
            $normalized = [
                'dn' => $row['dn'] ?? '',
            ];

            foreach ($row as $key => $value) {
                if (is_int($key) || $key === 'count' || $key === 'dn') {
                    continue;
                }

                $values = [];
                $valueCount = (int) ($value['count'] ?? 0);

                for ($j = 0; $j < $valueCount; $j++) {
                    if (isset($value[$j])) {
                        $values[] = (string) $value[$j];
                    }
                }

                $normalized[strtolower((string) $key)] = $values;
            }

            $out[] = $normalized;
        }

        return $out;
    }

    private function readEntry(mixed $ldap, string $dn): array
    {
        $result = @ldap_read($ldap, $dn, '(objectClass=*)', []);

        if (! $result) {
            throw new \RuntimeException('Gagal read entry: ' . ldap_error($ldap));
        }

        $entries = @ldap_get_entries($ldap, $result);

        if (($entries['count'] ?? 0) < 1) {
            throw new \RuntimeException('Entry tidak ditemukan: ' . $dn);
        }

        $row = $entries[0];
        $out = [
            'dn' => $dn,
        ];

        foreach ($row as $key => $value) {
            if (is_int($key) || $key === 'count' || $key === 'dn') {
                continue;
            }

            $values = [];
            $valueCount = (int) ($value['count'] ?? 0);

            for ($i = 0; $i < $valueCount; $i++) {
                if (isset($value[$i])) {
                    $values[] = (string) $value[$i];
                }
            }

            $out[strtolower((string) $key)] = $values;
        }

        return $out;
    }

    private function connectFromOperation(LdapCrudOperation $operation): mixed
    {
        $connection = LdapConnection::query()->find($operation->ldap_connection_id);

        if (! $connection) {
            return null;
        }

        $host = trim((string) ($connection->host ?? ''));

        if ($host === '') {
            return null;
        }

        $port = (int) ($connection->port ?? 389);
        $encryption = strtolower((string) ($connection->encryption ?? $connection->security ?? ''));
        $useSsl = in_array($encryption, ['ssl', 'ldaps'], true) || (bool) ($connection->use_ssl ?? false);

        if (str_starts_with($host, 'ldap://') || str_starts_with($host, 'ldaps://')) {
            $uri = $host;
        } else {
            $uri = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        }

        $ldap = @ldap_connect($uri);

        if (! $ldap) {
            return null;
        }

        @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
        @ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, 15);

        $bindDn = (string) (
            $connection->bind_dn
            ?? $connection->bind_user
            ?? $connection->username
            ?? $connection->admin_dn
            ?? $connection->manager_dn
            ?? ''
        );

        $bindPassword = (string) (
            $connection->bind_password
            ?? $connection->password
            ?? $connection->admin_password
            ?? $connection->manager_password
            ?? ''
        );

        if ($bindDn !== '') {
            return @ldap_bind($ldap, $bindDn, $bindPassword) ? $ldap : null;
        }

        return @ldap_bind($ldap) ? $ldap : null;
    }

    private function validateObjectClassRules(LdapCrudOperation $operation): array
    {
        if (! in_array($operation->operation_kind, ['add_objectclass', 'delete_objectclass'], true)) {
            return ['ok' => true];
        }

        if (blank($operation->objectclass_name)) {
            return $this->fail('ObjectClass wajib dipilih.');
        }

        if ($operation->operation_kind === 'add_objectclass') {
            $mustAttributes = app(LdapSchemaDropdownService::class)
                ->mustAttributes($operation->ldap_connection_id, $operation->objectclass_name);

            $values = is_array($operation->objectclass_must_values)
                ? $operation->objectclass_must_values
                : [];

            $missing = [];

            foreach ($mustAttributes as $attribute) {
                if (! array_key_exists($attribute, $values) || blank($values[$attribute])) {
                    $missing[] = $attribute;
                }
            }

            if (! empty($missing)) {
                return $this->fail('MUST attribute wajib diisi sebelum Add ObjectClass: ' . implode(', ', $missing));
            }
        }

        return ['ok' => true];
    }

    private function effectiveBaseDn(LdapCrudOperation $operation): string
    {
        if (($operation->target_mode ?? 'base_dn') === 'custom_dn') {
            return trim((string) ($operation->custom_target_dn ?? ''));
        }

        return trim((string) ($operation->base_dn ?? ''));
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'entries' => [],
            'results' => [],
        ];
    }

    private function writeItemLog(LdapCrudOperation $operation, array $row): void
    {
        if (! Schema::hasTable('ldap_crud_operation_logs')) {
            return;
        }

        DB::table('ldap_crud_operation_logs')->insert([
            'ldap_crud_operation_id' => $operation->id,
            'ldap_connection_id' => $operation->ldap_connection_id,
            'operation_kind' => $operation->operation_kind,
            'target_dn' => $row['dn'] ?? null,
            'status' => $row['status'] ?? 'unknown',
            'reason' => $row['reason'] ?? null,
            'payload' => json_encode($operation->toArray()),
            'result' => json_encode($row),
            'executed_by' => Auth::id(),
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writeAudit(LdapCrudOperation $operation, string $action, string $status, string $message): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $this->insertOnlyExistingColumns('audit_logs', [
            'module' => 'ldap_bulk_operations',
            'action' => $action,
            'status' => $status,
            'target_type' => 'ldap_crud_operation',
            'target_key' => (string) $operation->id,
            'target_dn' => $operation->custom_target_dn ?: $operation->base_dn,
            'after_value' => json_encode([
                'message' => $message,
                'operation' => $operation->toArray(),
            ]),
            'actor_id' => Auth::id(),
            'actor_name' => Auth::user()?->name,
            'actor_email' => Auth::user()?->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writeOperationLog(LdapCrudOperation $operation, string $action, string $status, string $message): void
    {
        foreach (['operation_job_logs', 'operation_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->insertOnlyExistingColumns($table, [
                'operation_job_id' => null,
                'level' => $status === 'success' ? 'info' : 'error',
                'status' => $status,
                'message' => '[LDAP Bulk Operations] ' . $action . ' - ' . $message,
                'context' => json_encode($operation->toArray()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }
    }

    private function insertOnlyExistingColumns(string $table, array $data): void
    {
        try {
            $columns = Schema::getColumnListing($table);
            $filtered = array_intersect_key($data, array_flip($columns));

            if (! empty($filtered)) {
                DB::table($table)->insert($filtered);
            }
        } catch (Throwable) {
            //
        }
    }
}
