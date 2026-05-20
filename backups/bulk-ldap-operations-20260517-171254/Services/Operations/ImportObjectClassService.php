<?php

namespace App\Services\Operations;

class ImportObjectClassService
{
    public function normalizeObjectClasses(mixed $value, array $fallback = []): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $text = trim((string) $value);

            if ($text === '') {
                $items = $fallback;
            } else {
                $items = preg_split('/[;,|]+/', $text) ?: [];
            }
        }

        $items = collect($items)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($items === []) {
            $items = $fallback;
        }

        if (! in_array('top', $items, true)) {
            array_unshift($items, 'top');
        }

        return collect($items)
            ->unique()
            ->values()
            ->all();
    }

    public function defaultUserObjectClasses(): array
    {
        return [
            'top',
            'person',
            'organizationalPerson',
            'inetOrgPerson',
        ];
    }

    public function defaultUserAttributes(): array
    {
        return [
            'uid',
            'cn',
            'sn',
            'givenName',
            'mail',
            'description',
        ];
    }

    public function petraPersonAttributes(): array
    {
        return [
            'petraNrp',
            'petraAffiliation',
            'petraFaculty',
            'petraDepartment',
        ];
    }

    public function requiredAttributesFor(array $objectClasses): array
    {
        $objectClasses = array_map('strtolower', $objectClasses);

        $required = [];

        if (in_array('person', $objectClasses, true)) {
            $required[] = 'cn';
            $required[] = 'sn';
        }

        if (in_array('inetorgperson', $objectClasses, true)) {
            $required[] = 'uid';
            $required[] = 'mail';
        }

        if (in_array('petraperson', $objectClasses, true)) {
            $required[] = 'petraNrp';
            $required[] = 'petraAffiliation';
        }

        return collect($required)
            ->unique()
            ->values()
            ->all();
    }

    public function allowedAttributesFor(array $objectClasses, array $customAttributes = []): array
    {
        $objectClassesLower = array_map('strtolower', $objectClasses);

        $allowed = $this->defaultUserAttributes();

        if (in_array('petraperson', $objectClassesLower, true)) {
            $allowed = array_merge($allowed, $this->petraPersonAttributes());
        }

        $allowed = array_merge($allowed, $customAttributes);

        return collect($allowed)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function parseRowObjectClasses(array $row, array $fallback = []): array
    {
        $value = $row['objectClass']
            ?? $row['objectclass']
            ?? $row['object_classes']
            ?? $row['objectClasses']
            ?? null;

        return $this->normalizeObjectClasses($value, $fallback ?: $this->defaultUserObjectClasses());
    }

    public function validateCreateRow(array $row, array $objectClasses): array
    {
        $errors = [];
        $required = $this->requiredAttributesFor($objectClasses);

        foreach ($required as $attribute) {
            if (! array_key_exists($attribute, $row) || trim((string) $row[$attribute]) === '') {
                $errors[] = "Missing required attribute: {$attribute}";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'required_attributes' => $required,
        ];
    }

    public function objectClassesToLdifLines(array $objectClasses): array
    {
        return collect($objectClasses)
            ->map(fn (string $objectClass): string => 'objectClass: '.$objectClass)
            ->values()
            ->all();
    }

    public function csvObjectClassValue(array $objectClasses, string $separator = ';'): string
    {
        return implode($separator ?: ';', $this->normalizeObjectClasses($objectClasses));
    }
}
