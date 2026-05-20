<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateImportTemplate extends CreateRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ImportTemplateResource::normalizeFormData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
