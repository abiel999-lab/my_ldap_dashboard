<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DirectoryExtendedSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Directory Foundation Coverage';

    protected function getStats(): array
    {
        return [
            Stat::make('Applications', $this->countTable('ldap_application_entries'))
                ->description('Application registry entries')
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->color('primary'),

            Stat::make('Units / OU', $this->countTable('ldap_unit_entries'))
                ->description('Organizational units')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('gray'),

            Stat::make('Schema Entries', $this->countTable('ldap_schema_entries'))
                ->description('ObjectClasses and attributes')
                ->descriptionIcon('heroicon-o-book-open')
                ->color('info'),

            Stat::make('Directory Explorer', $this->countTable('ldap_directory_entries'))
                ->description('Generic LDAP entries')
                ->descriptionIcon('heroicon-o-folder-open')
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
