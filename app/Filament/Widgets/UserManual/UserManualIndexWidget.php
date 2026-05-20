<?php

namespace App\Filament\Widgets\UserManual;

use App\Filament\Pages\Miscellaneous\UserManual;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualIndexWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Manual Sections';

    protected function getStats(): array
    {
        return [
            Stat::make('Pengantar Aplikasi', 'Administrasi LDAP')
                ->description('Tujuan aplikasi, prinsip aman, dan alur kerja dasar.')
                ->descriptionIcon('heroicon-o-information-circle')
                ->color('info')
                ->url(UserManual::getUrl(['section' => 'pengantar'])),

            Stat::make('Dashboard', 'Pemantauan Awal')
                ->description('Cara membaca summary, gagal jobs, audit, dan operation status.')
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->color('gray')
                ->url(UserManual::getUrl(['section' => 'dashboard'])),

            Stat::make('Directory Management', 'Objek LDAP')
                ->description('LDAP servers, penggunas, grups, OU, object manager, dan schema.')
                ->descriptionIcon('heroicon-o-folder')
                ->color('primary')
                ->url(UserManual::getUrl(['section' => 'directory'])),

            Stat::make('LDAP Connections', 'Konfigurasi Koneksi')
                ->description('Host, port, bind DN, base DN, SSL/TLS, mapping, dan read-only.')
                ->descriptionIcon('heroicon-o-link')
                ->color('primary')
                ->url(UserManual::getUrl(['section' => 'connections'])),

            Stat::make('Operations', 'Pekerjaan Administratif')
                ->description('Export, impor, transfer, sinkronisasi, antrean, operation jobs, dan command logs.')
                ->descriptionIcon('heroicon-o-command-line')
                ->color('warning')
                ->url(UserManual::getUrl(['section' => 'operations'])),

            Stat::make('Bulk Operations', 'Pratinjau Terlebih Dahulu')
                ->description('Add objectClass, delete objectClass, move DN, delete entry, dan rollback.')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(UserManual::getUrl(['section' => 'bulk'])),

            Stat::make('Import Export', 'Perpindahan Data')
                ->description('Backup LDIF, impor plan, transfer antar LDAP, dan refresh cache.')
                ->descriptionIcon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(UserManual::getUrl(['section' => 'impor-ekspor'])),

            Stat::make('Observability', 'Log dan Kesehatan Sistem')
                ->description('Audit logs, gagal jobs, system logs, health checks, dan executions.')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color('success')
                ->url(UserManual::getUrl(['section' => 'observability'])),

            Stat::make('LDAP Concepts', 'DN, RDN, dan Filter')
                ->description('Base DN, DN, RDN, objectClass, MUST, MAY, dan LDAP filter.')
                ->descriptionIcon('heroicon-o-academic-cap')
                ->color('gray')
                ->url(UserManual::getUrl(['section' => 'concepts'])),

            Stat::make('Troubleshooting', 'Penanganan Kesalahan')
                ->description('LDAP gagal, pratinjau kosong, antrean macet, schema error, dan database error.')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color('danger')
                ->url(UserManual::getUrl(['section' => 'troubleshooting'])),

            Stat::make('SOP Administrator', 'Daftar Pemeriksaan Harian')
                ->description('Urutan aman sebelum, saat, dan setelah menjalankan operasi LDAP.')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->url(UserManual::getUrl(['section' => 'sop'])),
        ];
    }
}
