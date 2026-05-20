<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IamCleanMissingFromLdapCacheCommand extends Command
{
    protected $signature = 'iam:clean-missing-from-ldap-cache {--force : Actually delete rows from PostgreSQL cache}';

    protected $description = 'Clean LDAP cache rows marked as missing_from_ldap. Without --force, only shows counts.';

    public function handle(): int
    {
        $tables = [
            'ldap_directory_entries',
            'ldap_user_entries',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn($table.' table not found.');
                continue;
            }

            $query = DB::table($table)->where('status', 'missing_from_ldap');
            $count = $query->count();

            if (! $this->option('force')) {
                $this->line($table.': '.$count.' missing_from_ldap rows found.');
                continue;
            }

            $deleted = $query->delete();
            $this->info($table.': deleted '.$deleted.' missing_from_ldap rows.');
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run only. Add --force to delete from PostgreSQL cache.');
        }

        return self::SUCCESS;
    }
}
