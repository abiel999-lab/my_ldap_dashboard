<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use Filament\Resources\Pages\EditRecord;

class EditImportBatch extends EditRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
