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
    public function allTypes(bool $navigationOnly = false): array
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

            $this->applyEnabledFilter($query, $columns);

            if ($navigationOnly) {
                $this->applyAutoOuNavigationFilter($query, $columns);
            }

            $sortColumn = $this->firstExistingColumn($columns, [
                'priority',
                'navigation_sort',
                'sort',
                'sort_order',
                'position',
                'id',
            ]);

            if ($sortColumn) {
                $query->orderBy($sortColumn);
            }

            return $query
                ->get()
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

    public function navigationTypes(): array
    {
        return $this->allTypes(navigationOnly: true);
    }

    public function findType(string $key): ?array
    {
        $key = Str::slug($key, '_');

        foreach ($this->allTypes(navigationOnly: false) as $type) {
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

        $columns = Schema::getColumnListing($query->getModel()->getTable());

        $baseDn = $type['base_dn'] ?? null;
        $ldapConnectionId = $type['ldap_connection_id'] ?? null;

        if ($ldapConnectionId && in_array('ldap_connection_id', $columns, true)) {
            $query->where('ldap_connection_id', $ldapConnectionId);
        }

        if ($baseDn && in_array('dn', $columns, true)) {
            $normalizedBaseDn = mb_strtolower($baseDn);

            $query->where(function (Builder $query) use ($normalizedBaseDn): void {
                $query
                    ->whereRaw('LOWER(dn) = ?', [$normalizedBaseDn])
                    ->orWhereRaw('LOWER(dn) LIKE ?', ['%,'.$normalizedBaseDn]);
            });

            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    private function normalizeRule(object $rule, array $columns): array
    {
        $key = $this->valueFromColumns($rule, $columns, [
            'rule_key',
            'key',
            'slug',
            'code',
        ]);

        $label = $this->valueFromColumns($rule, $columns, [
            'name',
            'label',
            'navigation_label',
            'display_name',
        ]);

        $baseDn = $this->valueFromColumns($rule, $columns, [
            'base_dn',
            'parent_dn',
            'search_base_dn',
            'default_base_dn',
        ]);

        $entryType = $this->valueFromColumns($rule, $columns, [
            'entry_type',
            'type',
        ]);

        $category = $this->valueFromColumns($rule, $columns, [
            'entry_category',
            'category',
        ]);

        $ldapConnectionId = $this->valueFromColumns($rule, $columns, [
            'ldap_connection_id',
            'connection_id',
        ]);

        $sort = $this->valueFromColumns($rule, $columns, [
            'priority',
            'navigation_sort',
            'sort',
            'sort_order',
            'position',
            'id',
        ]);

        $description = $this->valueFromColumns($rule, $columns, [
            'description',
            'notes',
        ]);

        return [
            'id' => $rule->id ?? null,
            'key' => Str::slug((string) $key, '_'),
            'label' => $this->humanLabel((string) ($label ?: $key)),
            'base_dn' => $baseDn ? trim((string) $baseDn) : null,
            'entry_type' => $entryType ? trim((string) $entryType) : null,
            'category' => $category ? trim((string) $category) : null,
            'ldap_connection_id' => is_numeric($ldapConnectionId) ? (int) $ldapConnectionId : null,
            'sort' => is_numeric($sort) ? (int) $sort : 1000,
            'description' => $description ? (string) $description : '',
            'icon' => 'heroicon-o-folder',
        ];
    }

    private function applyEnabledFilter(Builder $query, array $columns): void
    {
        foreach (['enabled', 'is_enabled', 'active', 'is_active'] as $column) {
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
                        'ready',
                        'production_ready',
                    ]);
            });
        }
    }

    private function applyAutoOuNavigationFilter(Builder $query, array $columns): void
    {
        $query->where(function (Builder $query) use ($columns): void {
            $hasCondition = false;

            foreach (['entry_type', 'type'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, 'dynamic_ou');
                    $hasCondition = true;
                }
            }

            foreach (['entry_category', 'category'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, 'dynamic_directory');
                    $hasCondition = true;
                }
            }

            foreach (['description', 'notes'] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orWhere($column, 'like', '%'.RootOuEntryTypeRegistrySyncService::AUTO_MARKER.'%');
                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $query->whereRaw('1 = 0');
            }
        });
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

        return Str::of(str_replace(['-', '_'], ' ', $value))
            ->headline()
            ->toString();
    }
}
