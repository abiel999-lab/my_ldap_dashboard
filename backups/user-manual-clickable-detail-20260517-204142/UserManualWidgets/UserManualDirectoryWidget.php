<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualDirectoryWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Directory Management';

    protected function getStats(): array
    {
        return [
            Stat::make('LDAP Connections', 'Connection Config')
                ->description('Menyimpan host, port, base DN, bind DN, password, SSL/TLS, user base DN, group base DN, mapping attribute, default connection, dan read-only mode.')
                ->descriptionIcon('heroicon-o-link')
                ->color('primary'),

            Stat::make('Users', 'LDAP Users')
                ->description('Digunakan untuk melihat DN, uid, cn, mail, objectClass, raw attributes, sync status, dan operasi terhadap user tertentu.')
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),

            Stat::make('Directory Object Manager', 'Generic LDAP Objects')
                ->description('Digunakan untuk create object, sync object, delete LDAP object, add/remove objectClass, rename RDN, move DN, bulk delete, dan bulk move.')
                ->descriptionIcon('heroicon-o-folder')
                ->color('success'),

            Stat::make('Schema Browser', 'ObjectClass & Attributes')
                ->description('Digunakan untuk membaca structural objectClass, auxiliary objectClass, MUST attribute, MAY attribute, syntax, dan aturan schema LDAP.')
                ->descriptionIcon('heroicon-o-book-open')
                ->color('gray'),

            Stat::make('Groups', 'LDAP Groups')
                ->description('Digunakan untuk melihat group DN, cn, member, objectClass, status, source, dan mapping group untuk aplikasi atau role.')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Units / OU', 'Organization Units')
                ->description('Digunakan untuk melihat OU, parent DN, child count, struktur organisasi, dan target move atau target filter operasi.')
                ->descriptionIcon('heroicon-o-building-office')
                ->color('warning'),
        ];
    }
}
