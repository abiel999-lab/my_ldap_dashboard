<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualOperationsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Operations Manual';

    protected function getStats(): array
    {
        return [
            Stat::make('LDIF Exports', 'Backup / Export')
                ->description('Pilih LDAP Connection, Base DN, Search Scope, LDAP Filter, Attributes, Size Limit, lalu jalankan export untuk menghasilkan LDIF.')
                ->descriptionIcon('heroicon-o-arrow-down-tray')
                ->color('info'),

            Stat::make('LDAP Import Center', 'Import Data')
                ->description('Buat template, upload data, generate plan, cek konflik, apply jika aman, lalu cek Operation Jobs dan Audit Logs.')
                ->descriptionIcon('heroicon-o-arrow-up-tray')
                ->color('success'),

            Stat::make('LDAP Transfer Center', 'Transfer Antar LDAP')
                ->description('Pilih source LDAP, target LDAP, source base DN, filter, target parent DN, collision strategy, exclude operational attributes, lalu preview.')
                ->descriptionIcon('heroicon-o-arrows-right-left')
                ->color('warning'),

            Stat::make('LDAP Sync Center', 'Refresh Cache')
                ->description('Digunakan setelah data LDAP berubah di luar aplikasi, setelah import, setelah transfer, atau saat tabel aplikasi tidak sesuai LDAP asli.')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->color('primary'),

            Stat::make('Operation Jobs', 'Tracking Operasi')
                ->description('Melihat status queued, running, success, failed, partial failed, skipped, item per DN, log job, total OK, dan total failed.')
                ->descriptionIcon('heroicon-o-queue-list')
                ->color('gray'),

            Stat::make('Queue Jobs', 'Laravel Queue')
                ->description('Memantau job yang menunggu atau berjalan. Jika job menumpuk, cek worker, Failed Jobs, dan queue restart.')
                ->descriptionIcon('heroicon-o-clock')
                ->color('danger'),
        ];
    }
}
