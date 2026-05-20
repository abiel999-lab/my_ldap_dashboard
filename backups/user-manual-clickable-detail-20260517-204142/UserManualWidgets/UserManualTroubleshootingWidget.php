<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualTroubleshootingWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Troubleshooting';

    protected function getStats(): array
    {
        return [
            Stat::make('LDAP Connection Gagal', 'Check Config')
                ->description('Cek host, port, firewall, bind DN, password, SSL/TLS, Base DN, dan schema test.')
                ->descriptionIcon('heroicon-o-wifi')
                ->color('danger'),

            Stat::make('Preview Kosong', 'Filter / Scope Salah')
                ->description('Cek Base DN, LDAP Filter, Search Scope. Coba filter aman: (objectClass=*).')
                ->descriptionIcon('heroicon-o-magnifying-glass-circle')
                ->color('warning'),

            Stat::make('Apply Tidak Terlihat', 'Refresh LDAP')
                ->description('Refresh Apache Directory Studio, cek LDAP Connection yang dipakai, cek Operation Jobs, Audit Logs, dan Execution Result.')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->color('info'),

            Stat::make('ObjectClass Gagal', 'Schema Error')
                ->description('Pastikan objectClass auxiliary, MUST attribute lengkap, dan attribute sesuai schema.')
                ->descriptionIcon('heroicon-o-exclamation-circle')
                ->color('danger'),

            Stat::make('Queue Tidak Jalan', 'Worker Problem')
                ->description('Cek Queue Jobs, Failed Jobs, queue worker, queue restart, timeout, dan failed exception.')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning'),

            Stat::make('Database Error', 'Migration / Column')
                ->description('Cek System Logs, migration, model cast, column type, uuid, dan JSON payload.')
                ->descriptionIcon('heroicon-o-circle-stack')
                ->color('danger'),
        ];
    }
}
