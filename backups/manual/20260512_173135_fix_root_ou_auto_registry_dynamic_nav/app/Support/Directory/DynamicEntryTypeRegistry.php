<?php

namespace App\Support\Directory;

use App\Models\Directory\LdapEntryTypeRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DynamicEntryTypeRegistry
{
    /**
     * Read Entry Type Registry dynamically.
     *
     * This class intentionally avoids hardcoding column names too tightly.
     * It supports multiple possible schema versions by detecting columns.
     */
    public function allTypes(): array
    {
        try {
            if (! class_exists(LdapEntryTypeRule::class)) {
                return [];
            }

            $model = new LdapEntryTypeRule();
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                return [];
            }

            $columns = Schema::getColumnListing($table);

            $query = LdapEntryTypeRule::query();

            $this->applyActiveFilterIfAvailable($query, $columns);

            $sortColumn = $this->firstExistingColumn($columns, [
                'navigation_sort',
                'sort',
                'sort_order',
                'position',
                'id',
            ]);

            if ($sortColumn) {
                $query->orderBy($sortColumn);
            }

            $rules = $query->get();

            return $rules
                ->map(fn ($rule): array => $this->normalizeRule($rule, $columns))
                ->filter(fn (array $type): bool => $type['key'] !== '')
                ->unique('key')
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::error('DynamicEntryTypeRegistry allTypes failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function findType(string $key): ?array
    {
        $key = Str::slug($key, '_');

        foreach ($this->allTypes() as $type) {
            if (($type['key'] ?? null) === $key) {
                return $type;
            }
        }

        return null;
    }

    public function filterDirectoryQuery(Builder $query, string $entryTypeKey): Builder
    {
        $type = $this->findType($entryTypeKey);

        if (! $type) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);

        $key = $type['key'];
        $baseDn = $type['base_dn'] ?? null;
        $objectClass = $type['object_class'] ?? null;
        $ldapFilter = $type['ldap_filter'] ?? null;

        return $query->where(function (Builder $q) use ($columns, $key, $baseDn, $objectClass, $ldapFilter): void {
            $matchedSomething = false;

            foreach (['entry_type', 'type', 'category', 'kind'] as $column) {
                if (in_array($column, $columns, true)) {
                    $q->orWhere($column, $key);
                    $matchedSomething = true;
                }
            }

            foreach (['entry_key', 'key', 'slug'] as $column) {
                if (in_array($column, $columns, true)) {
                    $q->orWhere($column, $key);
                    $matchedSomething = true;
                }
            }

            if ($baseDn && in_array('dn', $columns, true)) {
                $q->orWhere('dn', 'ilike', '%'.$baseDn);
                $matchedSomething = true;
            }

            if ($objectClass) {
                foreach (['object_classes', 'object_class', 'objectClass'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $q->orWhere($column, 'ilike', '%'.$objectClass.'%');
                        $matchedSomething = true;
                    }
                }

                foreach (['attributes', 'raw_attributes', 'normal_attributes'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $q->orWhere($column, 'ilike', '%'.$objectClass.'%');
                        $matchedSomething = true;
                    }
                }
            }

            if ($ldapFilter) {
                $filterToken = $this->extractMostUsefulFilterToken($ldapFilter);

                if ($filterToken !== null) {
                    foreach (['dn', 'rdn', 'cn', 'uid', 'ou', 'attributes', 'raw_attributes', 'normal_attributes'] as $column) {
                        if (in_array($column, $columns, true)) {
                            $q->orWhere($column, 'ilike', '%'.$filterToken.'%');
                            $matchedSomething = true;
                        }
                    }
                }
            }

            if (! $matchedSomething) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    private function normalizeRule(object $rule, array $columns): array
    {
        $key = $this->valueFromColumns($rule, $columns, [
            'key',
            'slug',
            'code',
            'entry_type',
            'type',
            'name',
        ]);

        $label = $this->valueFromColumns($rule, $columns, [
            'label',
            'navigation_label',
            'display_name',
            'name',
            'entry_type',
            'type',
            'key',
            'slug',
        ]);

        $pluralLabel = $this->valueFromColumns($rule, $columns, [
            'plural_label',
            'navigation_plural_label',
            'menu_label',
        ]);

        $baseDn = $this->valueFromColumns($rule, $columns, [
            'base_dn',
            'parent_dn',
            'search_base_dn',
            'default_base_dn',
            'dn_scope',
        ]);

        $ldapFilter = $this->valueFromColumns($rule, $columns, [
            'ldap_filter',
            'filter',
            'search_filter',
            'object_filter',
            'match_filter',
        ]);

        $objectClass = $this->valueFromColumns($rule, $columns, [
            'object_class',
            'objectClass',
            'primary_object_class',
            'structural_object_class',
        ]);

        $icon = $this->valueFromColumns($rule, $columns, [
            'icon',
            'navigation_icon',
        ]);

        $sort = $this->valueFromColumns($rule, $columns, [
            'navigation_sort',
            'sort',
            'sort_order',
            'position',
            'id',
        ]);

        $key = Str::slug((string) $key, '_');

        return [
            'id' => $rule->id ?? null,
            'key' => $key,
            'label' => $this->humanLabel($pluralLabel ?: $label ?: $key),
            'singular_label' => $this->humanLabel($label ?: $key),
            'base_dn' => $baseDn ? trim((string) $baseDn) : null,
            'ldap_filter' => $ldapFilter ? trim((string) $ldapFilter) : null,
            'object_class' => $objectClass ? trim((string) $objectClass) : null,
            'icon' => $icon ? trim((string) $icon) : 'heroicon-o-folder-open',
            'sort' => is_numeric($sort) ? (int) $sort : 1000,
        ];
    }

    private function applyActiveFilterIfAvailable(Builder $query, array $columns): void
    {
        foreach (['is_active', 'active', 'enabled', 'is_enabled'] as $column) {
            if (in_array($column, $columns, true)) {
                $query->where($column, true);
                return;
            }
        }

        if (in_array('status', $columns, true)) {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhereIn('status', [
                        'active',
                        'enabled',
                        'production_ready',
                        'ready',
                    ]);
            });
        }
    }

    private function valueFromColumns(object $model, array $columns, array $candidates): mixed
    {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $value = $model->{$column} ?? null;

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function humanLabel(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Dynamic Entries';
        }

        $value = str_replace(['-', '_'], ' ', $value);

        return Str::of($value)->headline()->toString();
    }

    private function extractMostUsefulFilterToken(string $filter): ?string
    {
        if (preg_match('/objectClass\s*=\s*([a-zA-Z0-9_.-]+)/i', $filter, $matches)) {
            return $matches[1];
        }

        if (preg_match('/ou\s*=\s*([a-zA-Z0-9_.-]+)/i', $filter, $matches)) {
            return $matches[1];
        }

        if (preg_match('/cn\s*=\s*([a-zA-Z0-9_.-]+)/i', $filter, $matches)) {
            return $matches[1];
        }

        if (preg_match('/uid\s*=\s*([a-zA-Z0-9_.-]+)/i', $filter, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
