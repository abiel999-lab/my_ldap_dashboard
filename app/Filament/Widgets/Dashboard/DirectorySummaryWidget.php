<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DirectorySummaryWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Directory Summary';

    protected function getColumns(): int
    {
        return 6;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('LDAP Servers', $this->countTable('ldap_servers'))
                ->description('Registered LDAP server configs')
                ->descriptionIcon('heroicon-o-circle-stack')
                ->color('primary'),

            Stat::make('LDAP Connections', $this->countTable('ldap_connections'))
                ->description('Configured LDAP servers')
                ->descriptionIcon('heroicon-o-server-stack')
                ->color('primary'),

            Stat::make('Users', $this->countTable('ldap_user_entries'))
                ->description('Cached LDAP users')
                ->descriptionIcon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Groups', $this->countTable('ldap_group_entries'))
                ->description('Cached LDAP groups')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Schema Entries', $this->countTable('ldap_schema_entries'))
                ->description('ObjectClasses and attributes')
                ->descriptionIcon('heroicon-o-book-open')
                ->color('primary'),

            Stat::make('Directory Objects', $this->countTable('ldap_directory_entries'))
                ->description('Generic LDAP entries')
                ->descriptionIcon('heroicon-o-folder')
                ->color('success'),
        ];
    }

    private function countTable(string $table): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
