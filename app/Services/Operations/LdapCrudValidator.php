<?php

namespace App\Services\Operations;

use App\Models\Operations\LdapCrudOperation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LdapCrudValidator
{
    public function validate(LdapCrudOperation $operation): array
    {
        $errors = [];

        if (! $operation->ldap_connection_id) {
            $errors[] = 'LDAP connection is required.';
        }

        if (! in_array($operation->operation_type, [
            'create_entry',
            'modify_entry',
            'delete_entry',
            'rename_dn',
            'move_dn',
        ], true)) {
            $errors[] = 'Invalid LDAP CRUD operation type.';
        }

        if (blank($operation->target_dn)) {
            $errors[] = 'Target DN is required.';
        }

        if (! blank($operation->target_dn) && ! $this->looksLikeDn((string) $operation->target_dn)) {
            $errors[] = 'Target DN format looks invalid.';
        }

        if ($operation->operation_type === 'create_entry') {
            if (($operation->object_classes ?? []) === []) {
                $errors[] = 'Create entry requires at least one objectClass.';
            }

            if (($operation->attributes ?? []) === []) {
                $errors[] = 'Create entry requires attributes.';
            }
        }

        if ($operation->operation_type === 'modify_entry') {
            if (($operation->attribute_changes ?? []) === []) {
                $errors[] = 'Modify entry requires at least one attribute change.';
            }

            foreach (($operation->attribute_changes ?? []) as $index => $change) {
                $attribute = trim((string) Arr::get($change, 'attribute', ''));
                $action = trim((string) Arr::get($change, 'action', ''));

                if ($attribute === '') {
                    $errors[] = 'Modify change row '.($index + 1).' is missing attribute.';
                }

                if (! in_array($action, ['add', 'replace', 'delete'], true)) {
                    $errors[] = 'Modify change row '.($index + 1).' has invalid action.';
                }
            }
        }

        if (in_array($operation->operation_type, ['rename_dn', 'move_dn'], true)) {
            if (blank($operation->new_rdn)) {
                $errors[] = 'Rename/move operation requires new RDN.';
            }

            if (! blank($operation->new_rdn) && ! str_contains((string) $operation->new_rdn, '=')) {
                $errors[] = 'New RDN must look like uid=value or cn=value.';
            }
        }

        if ($operation->operation_type === 'move_dn') {
            if (blank($operation->parent_dn)) {
                $errors[] = 'Move DN operation requires new parent DN.';
            }

            if (! blank($operation->parent_dn) && ! $this->looksLikeDn((string) $operation->parent_dn)) {
                $errors[] = 'New parent DN format looks invalid.';
            }
        }

        if ($operation->operation_type === 'delete_entry' && ! $operation->destructive) {
            $errors[] = 'Delete entry is destructive. Destructive flag must be enabled before apply.';
        }

        return $errors;
    }

    private function looksLikeDn(string $dn): bool
    {
        $dn = trim($dn);

        if ($dn === '') {
            return false;
        }

        if (! str_contains($dn, '=')) {
            return false;
        }

        if (Str::contains($dn, ['password=', 'token=', 'secret='])) {
            return false;
        }

        return true;
    }
}
