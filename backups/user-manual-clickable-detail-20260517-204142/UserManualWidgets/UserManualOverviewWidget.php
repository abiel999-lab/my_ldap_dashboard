<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Manual Overview';

    protected function getStats(): array
    {
        return [
            Stat::make('Pengantar Aplikasi', 'Admin LDAP')
                ->description('Aplikasi untuk mengelola koneksi LDAP, user, group, role, OU, schema, import, export, transfer, sync, queue, audit log, system log, health check, dan Keycloak.')
                ->descriptionIcon('heroicon-o-information-circle')
                ->color('info'),

            Stat::make('Prinsip Utama', 'Preview First')
                ->description('Selalu cek LDAP Connection, Base DN, Target DN, LDAP Filter, Search Scope, dan hasil preview sebelum apply.')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('warning'),

            Stat::make('Alur Aman', 'Preview → Apply → Verify')
                ->description('Preview tidak mengubah LDAP. Apply menjalankan perubahan. Verify lewat Operation Jobs, Audit Logs, Apache Directory Studio, atau ldapsearch.')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color('success'),
        ];
    }
}
