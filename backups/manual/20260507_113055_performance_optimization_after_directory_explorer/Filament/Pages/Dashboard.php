<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Dashboard\IamSummaryStatsWidget;
use App\Filament\Widgets\Dashboard\HealthOverviewWidget;
use App\Filament\Widgets\Dashboard\LdapHealthWidget;
use App\Filament\Widgets\Dashboard\RecentAuditLogsWidget;
use App\Filament\Widgets\Dashboard\RecentOperationJobsWidget;
use App\Filament\Widgets\Dashboard\RecentCommandExecutionsWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    public function getWidgets(): array
    {
        return [
            IamSummaryStatsWidget::class,
            LdapHealthWidget::class,
            HealthOverviewWidget::class,
            RecentOperationJobsWidget::class,
            RecentCommandExecutionsWidget::class,
            RecentAuditLogsWidget::class,
        ];
    }
}
