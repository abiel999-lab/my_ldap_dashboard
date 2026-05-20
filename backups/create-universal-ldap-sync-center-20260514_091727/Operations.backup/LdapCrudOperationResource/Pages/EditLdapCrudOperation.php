<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLdapCrudOperation extends EditRecord
{
    protected static string $resource = LdapCrudOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
