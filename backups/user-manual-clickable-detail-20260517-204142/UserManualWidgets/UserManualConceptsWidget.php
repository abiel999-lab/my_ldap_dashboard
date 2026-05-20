<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualConceptsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'LDAP Concepts';

    protected function getStats(): array
    {
        return [
            Stat::make('Base DN', 'Titik Awal')
                ->description('Contoh: ou=alumni,ou=people,dc=petra,dc=ac,dc=id. Digunakan sebagai root pencarian LDAP.')
                ->descriptionIcon('heroicon-o-map')
                ->color('gray'),

            Stat::make('DN', 'Alamat Lengkap Entry')
                ->description('Contoh: uid=usr000046,ou=alumni,ou=people,dc=petra,dc=ac,dc=id.')
                ->descriptionIcon('heroicon-o-identification')
                ->color('gray'),

            Stat::make('RDN', 'Bagian Pertama DN')
                ->description('Contoh RDN: uid=usr000046. RDN Attribute adalah uid, RDN Value adalah usr000046.')
                ->descriptionIcon('heroicon-o-tag')
                ->color('gray'),

            Stat::make('LDAP Filter', '(objectClass=*)')
                ->description('Contoh lain: (uid=usr000046), (mail=*), (&(objectClass=inetOrgPerson)(uid=usr000046)).')
                ->descriptionIcon('heroicon-o-code-bracket')
                ->color('primary'),

            Stat::make('Structural ObjectClass', 'Jenis Utama Entry')
                ->description('Structural objectClass menentukan jenis utama entry. Umumnya tidak boleh sembarangan diganti atau dibuat dobel.')
                ->descriptionIcon('heroicon-o-cube')
                ->color('warning'),

            Stat::make('Auxiliary ObjectClass', 'Tambahan Attribute')
                ->description('Auxiliary objectClass menambahkan kemampuan attribute pada entry, misalnya domainRelatedObject membutuhkan associatedDomain.')
                ->descriptionIcon('heroicon-o-puzzle-piece')
                ->color('success'),
        ];
    }
}
