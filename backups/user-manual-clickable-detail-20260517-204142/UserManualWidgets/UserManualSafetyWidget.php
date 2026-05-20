<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualSafetyWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Bulk Operations Safety';

    protected function getStats(): array
    {
        return [
            Stat::make('Target Mode', 'Base DN / Custom DN / RDN')
                ->description('Gunakan Base DN + LDAP Filter untuk banyak entry, Custom Target DN untuk satu DN spesifik, dan RDN untuk target berbasis uid, cn, atau ou.')
                ->descriptionIcon('heroicon-o-map-pin')
                ->color('primary'),

            Stat::make('Search Scope', 'Base / One Level / Subtree')
                ->description('Base hanya membaca entry itu sendiri. One Level membaca child satu level. Subtree membaca semua child di bawah base DN.')
                ->descriptionIcon('heroicon-o-magnifying-glass')
                ->color('info'),

            Stat::make('Add ObjectClass', 'Auxiliary Only')
                ->description('Tambahkan auxiliary objectClass ke banyak entry. Jika objectClass punya MUST attribute, isi value wajib sebelum apply.')
                ->descriptionIcon('heroicon-o-plus-circle')
                ->color('success'),

            Stat::make('Delete ObjectClass', 'Hati-hati')
                ->description('Menghapus objectClass dapat membuat attribute terkait ikut tidak valid. Pastikan preview benar dan rollback tersedia.')
                ->descriptionIcon('heroicon-o-minus-circle')
                ->color('danger'),

            Stat::make('Move To OU', 'Pindah Parent DN')
                ->description('Pastikan target parent DN sudah ada. Jangan memindahkan OU besar tanpa memahami child object.')
                ->descriptionIcon('heroicon-o-arrow-right-circle')
                ->color('warning'),

            Stat::make('Delete Entry', 'Danger Zone')
                ->description('Jangan delete entry massal tanpa filter spesifik. Selalu test 1 sampai 3 entry dulu sebelum operasi besar.')
                ->descriptionIcon('heroicon-o-trash')
                ->color('danger'),
        ];
    }
}
