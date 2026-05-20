<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Services\Directory\LdapServerProvisioningService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLdapServer extends EditRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return LdapServerResource::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
