<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditImportTemplate extends EditRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ImportTemplateResource::normalizeFormData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
