<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Audit\AuditLog;
use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapDirectoryEntry;
use App\Models\Operations\QueueJob;
use App\Models\Operations\FailedQueueJob;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IamSummaryStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $totalLdapConnections = LdapConnection::query()->count();

        $healthyLdapConnections = LdapConnection::query()
            ->where('last_health_status', 'healthy')
            ->count();

        $cachedEntries = LdapDirectoryEntry::query()->count();

        $auditLogsToday = AuditLog::query()
            ->whereDate('created_at', today())
            ->count();

        $pendingJobs = QueueJob::query()->count();
        $failedJobs = FailedQueueJob::query()->count();

        return [
            Stat::make('LDAP Connections', $totalLdapConnections)
                ->description($healthyLdapConnections.' healthy connection(s)')
                ->descriptionIcon('heroicon-o-server-stack')
                ->color($healthyLdapConnections > 0 ? 'success' : 'warning'),

            Stat::make('Cached Directory Entries', $cachedEntries)
                ->description('Read-only LDAP browser cache')
                ->descriptionIcon('heroicon-o-folder')
                ->color($cachedEntries > 0 ? 'info' : 'gray'),

            Stat::make('Audit Logs Today', $auditLogsToday)
                ->description('Recorded admin/system activities')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color($auditLogsToday > 0 ? 'success' : 'gray'),

            Stat::make('Queue Jobs', $pendingJobs)
                ->description($failedJobs.' failed job(s)')
                ->descriptionIcon('heroicon-o-clock')
                ->color($failedJobs > 0 ? 'danger' : ($pendingJobs > 0 ? 'warning' : 'success')),
        ];
    }
}
