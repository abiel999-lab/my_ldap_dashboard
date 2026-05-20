<?php

namespace App\Filament\Resources\Operations\LdapCrudOperationResource\Pages;

use App\Filament\Resources\Operations\LdapCrudOperationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLdapCrudOperations extends ListRecords
{
    protected static string $resource = LdapCrudOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New LDAP Bulk Operation')
                ->modalHeading('Create LDAP Bulk Operation')
                ->modalSubmitActionLabel('Create')
                ->modalWidth('7xl')
                ->createAnother(false),
        ];
    }
}
