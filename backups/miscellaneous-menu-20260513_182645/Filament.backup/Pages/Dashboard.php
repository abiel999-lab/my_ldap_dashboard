<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Dashboard\DirectoryExtendedSummaryWidget;
use App\Filament\Widgets\Dashboard\DirectorySummaryWidget;
use App\Filament\Widgets\Dashboard\OperationsSummaryWidget;
use App\Filament\Widgets\Dashboard\RecentAuditLogsWidget;
use App\Filament\Widgets\Dashboard\RecentOperationJobsWidget;
use App\Filament\Widgets\Dashboard\SecurityActivitySummaryWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 0;

    public function getWidgets(): array
    {
        return [
            DirectorySummaryWidget::class,
            DirectoryExtendedSummaryWidget::class,
            OperationsSummaryWidget::class,
            SecurityActivitySummaryWidget::class,
            RecentOperationJobsWidget::class,
            RecentAuditLogsWidget::class,
        ];
    }
}
