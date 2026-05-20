<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLdapServers extends ListRecords
{
    protected static string $resource = LdapServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create LDAP Server')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
