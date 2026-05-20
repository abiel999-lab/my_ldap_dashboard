<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Services\Directory\LdapServerProvisioningService;
use Filament\Resources\Pages\CreateRecord;

class CreateLdapServer extends CreateRecord
{
    protected static string $resource = LdapServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LdapServerResource::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
    {
        app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($this->record);
    }
}
