<?php

namespace App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;

use App\Filament\Resources\Operations\BulkLdapOperationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBulkLdapOperations extends ListRecords
{
    protected static string $resource = BulkLdapOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
