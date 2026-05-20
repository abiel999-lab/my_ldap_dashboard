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

    protected function getStats(): array
    {
        return [
            Stat::make('LDAP Connections', $this->countTable('ldap_connections'))
                ->description('Configured LDAP servers')
                ->descriptionIcon('heroicon-o-server')
                ->color('primary'),

            Stat::make('Users', $this->countTable('ldap_user_entries'))
                ->description('Cached LDAP users')
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),

            Stat::make('Groups', $this->countTable('ldap_group_entries'))
                ->description('Cached LDAP groups')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Roles', $this->countTable('ldap_role_entries'))
                ->description('Cached LDAP roles')
                ->descriptionIcon('heroicon-o-key')
                ->color('warning'),
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
