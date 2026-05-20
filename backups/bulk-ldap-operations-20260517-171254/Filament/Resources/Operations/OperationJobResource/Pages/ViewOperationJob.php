<?php

namespace App\Filament\Resources\Operations\OperationJobResource\Pages;

use App\Filament\Resources\Operations\OperationJobItemResource;
use App\Filament\Resources\Operations\OperationJobLogResource;
use App\Filament\Resources\Operations\OperationJobResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationJob extends ViewRecord
{
    protected static string $resource = OperationJobResource::class;

    protected function getHeaderActions(): array
    {
        return [



            Action::make('viewItems')
                ->label('Open Items')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(fn (): string => OperationJobItemResource::getUrl('index')),

            Action::make('viewLogs')
                ->label('Open Logs')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (): string => OperationJobLogResource::getUrl('index')),
        ];
    }
}
