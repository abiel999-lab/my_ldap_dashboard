<?php

namespace App\Services\Operations;

use App\Models\Operations\LdapCrudOperation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LdapCrudLdifBuilder
{
    public function build(LdapCrudOperation $operation): string
    {
        return match ($operation->operation_type) {
            'create_entry' => $this->buildCreateEntry($operation),
            'modify_entry' => $this->buildModifyEntry($operation),
            'delete_entry' => $this->buildDeleteEntry($operation),
            'rename_dn' => $this->buildRenameDn($operation),
            'move_dn' => $this->buildMoveDn($operation),
            default => '# Unsupported operation type: '.$operation->operation_type.PHP_EOL,
        };
    }

    private function buildCreateEntry(LdapCrudOperation $operation): string
    {
        $lines = [];

        $lines[] = 'dn: '.$this->cleanDn((string) $operation->target_dn);
        $lines[] = 'changetype: add';

        foreach (($operation->object_classes ?? []) as $objectClass) {
            $objectClass = trim((string) $objectClass);

            if ($objectClass !== '') {
                $lines[] = 'objectClass: '.$objectClass;
            }
        }

        foreach (($operation->attributes ?? []) as $key => $value) {
            $attribute = trim((string) $key);

            if ($attribute === '' || Str::lower($attribute) === 'objectclass') {
                continue;
            }

            foreach ($this->asValues($value) as $singleValue) {
                $lines[] = $attribute.': '.$singleValue;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function buildModifyEntry(LdapCrudOperation $operation): string
    {
        $lines = [];

        $lines[] = 'dn: '.$this->cleanDn((string) $operation->target_dn);
        $lines[] = 'changetype: modify';

        foreach (($operation->attribute_changes ?? []) as $change) {
            $action = trim((string) Arr::get($change, 'action', 'replace'));
            $attribute = trim((string) Arr::get($change, 'attribute', ''));

            if ($attribute === '') {
                continue;
            }

            if (! in_array($action, ['add', 'replace', 'delete'], true)) {
                $action = 'replace';
            }

            $lines[] = $action.': '.$attribute;

            if ($action !== 'delete') {
                foreach ($this->asValues(Arr::get($change, 'values', [])) as $value) {
                    $lines[] = $attribute.': '.$value;
                }
            }

            $lines[] = '-';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function buildDeleteEntry(LdapCrudOperation $operation): string
    {
        return implode(PHP_EOL, [
            'dn: '.$this->cleanDn((string) $operation->target_dn),
            'changetype: delete',
        ]).PHP_EOL;
    }

    private function buildRenameDn(LdapCrudOperation $operation): string
    {
        return implode(PHP_EOL, [
            'dn: '.$this->cleanDn((string) $operation->target_dn),
            'changetype: modrdn',
            'newrdn: '.trim((string) $operation->new_rdn),
            'deleteoldrdn: 1',
        ]).PHP_EOL;
    }

    private function buildMoveDn(LdapCrudOperation $operation): string
    {
        return implode(PHP_EOL, [
            'dn: '.$this->cleanDn((string) $operation->target_dn),
            'changetype: modrdn',
            'newrdn: '.trim((string) $operation->new_rdn),
            'deleteoldrdn: 1',
            'newsuperior: '.$this->cleanDn((string) $operation->parent_dn),
        ]).PHP_EOL;
    }

    private function asValues(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item): string => trim((string) $item))
                ->filter(fn (string $item): bool => $item !== '')
                ->values()
                ->all();
        }

        $value = trim((string) $value);

        return $value === '' ? [] : [$value];
    }

    private function cleanDn(string $dn): string
    {
        return trim($dn);
    }
}
