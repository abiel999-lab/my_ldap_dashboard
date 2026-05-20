<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SecurityActivitySummaryWidget extends BaseWidget
{
    protected static ?int $sort = 40;

    protected ?string $heading = 'Security / Activity Summary';

    protected function getStats(): array
    {
        return [
            Stat::make('Audit Logs', $this->countTable('audit_logs'))
                ->description('Recorded admin activities')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color('success'),


            Stat::make('Import Batches', $this->countTable('import_batches'))
                ->description('Import preview/apply records')
                ->descriptionIcon('heroicon-o-arrow-up-tray')
                ->color('warning'),

            Stat::make('LDIF Exports', $this->countTable('ldif_export_batches'))
                ->description('LDIF export records')
                ->descriptionIcon('heroicon-o-arrow-down-tray')
                ->color('primary'),
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
