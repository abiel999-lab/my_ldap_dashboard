<?php

namespace App\Console\Commands;

use App\Services\Ldap\LdapEntryAttributeFormatterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IamPreviewUserAttributeStudioCommand extends Command
{
    protected $signature = 'iam:preview-user-attribute-studio {--uid=} {--dn=} {--id=}';

    protected $description = 'Preview formatted LDAP user attributes from cached database rows.';

    public function handle(): int
    {
        $tables = [
            'ldap_user_entries',
            'ldap_directory_entries',
            'ldap_users',
            'directory_entries',
        ];

        $uid = $this->option('uid');
        $dn = $this->option('dn');
        $id = $this->option('id');

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);

            if ($id) {
                $query->where('id', $id);
            } elseif ($dn) {
                $this->whereAnyExistingColumn($query, $table, ['dn', 'entry_dn', 'target_dn'], $dn);
            } elseif ($uid) {
                $this->whereAnyExistingColumn($query, $table, ['uid', 'entry_rdn', 'rdn'], $uid, true);
            } else {
                $query->orderByDesc('id');
            }

            $row = $query->first();

            if (! $row) {
                continue;
            }

            $this->info('Found row in table: '.$table);
            $this->newLine();

            $formatted = app(LdapEntryAttributeFormatterService::class)->formatForTextarea((array) $row);

            $this->line($formatted);

            return self::SUCCESS;
        }

        $this->error('No LDAP user/cache row found.');

        return self::FAILURE;
    }

    private function whereAnyExistingColumn($query, string $table, array $columns, string $value, bool $like = false): void
    {
        $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($existing, $value, $like): void {
            foreach ($existing as $index => $column) {
                if ($like) {
                    $index === 0
                        ? $q->where($column, 'like', '%'.$value.'%')
                        : $q->orWhere($column, 'like', '%'.$value.'%');
                } else {
                    $index === 0
                        ? $q->where($column, $value)
                        : $q->orWhere($column, $value);
                }
            }
        });
    }
}
