<?php

namespace App\Filament\Resources\Directory\LdapConnectionResource\Pages;

use App\Filament\Resources\Directory\LdapConnectionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapConnection extends ViewRecord
{
    protected static string $resource = LdapConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
