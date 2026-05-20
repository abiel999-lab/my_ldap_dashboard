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

            Stat::make('Units / OU', $this->countTable('ldap_unit_entries'))
                ->description('Organizational units')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('gray'),


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
