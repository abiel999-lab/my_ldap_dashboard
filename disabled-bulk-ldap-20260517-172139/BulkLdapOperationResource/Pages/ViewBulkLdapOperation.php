<?php

namespace App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;

use App\Filament\Resources\Operations\BulkLdapOperationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBulkLdapOperation extends ViewRecord
{
    protected static string $resource = BulkLdapOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
