<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OperationsSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 30;

    protected ?string $heading = 'Operations Summary';

    protected function getStats(): array
    {
        return [
            Stat::make('Queued Jobs', $this->countOperationJobsByStatus(['queued']))
                ->description('Waiting operation jobs')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning'),

            Stat::make('Running Jobs', $this->countOperationJobsByStatus(['running']))
                ->description('Active operation jobs')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->color('info'),

            Stat::make('Failed Operations', $this->countOperationJobsByStatus(['failed']))
                ->description('Failed operation jobs')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger'),

            Stat::make('Failed Queue Jobs', $this->countTable('failed_jobs'))
                ->description('Laravel failed queue jobs')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color('danger'),
        ];
    }

    private function countOperationJobsByStatus(array $statuses): int
    {
        try {
            if (! Schema::hasTable('operation_jobs')) {
                return 0;
            }

            return DB::table('operation_jobs')
                ->whereIn('status', $statuses)
                ->count();
        } catch (Throwable) {
            return 0;
        }
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
