<?php

namespace App\Filament\Resources\Directory\LdapConnectionResource\Pages;

use App\Filament\Resources\Directory\LdapConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLdapConnections extends ListRecords
{
    protected static string $resource = LdapConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New LDAP Connection'),
        ];
    }
}
