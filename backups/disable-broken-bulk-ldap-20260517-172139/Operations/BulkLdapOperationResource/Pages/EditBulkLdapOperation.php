<?php

namespace App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;

use App\Filament\Resources\Operations\BulkLdapOperationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBulkLdapOperation extends EditRecord
{
    protected static string $resource = BulkLdapOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
