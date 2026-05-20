<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportTemplates extends ListRecords
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Template'),
        ];
    }
}
