<?php

namespace App\Filament\Resources\Observability\FailedQueueJobResource\Pages;

use App\Filament\Resources\Observability\FailedQueueJobResource;
use Filament\Resources\Pages\ListRecords;

class ListFailedQueueJobs extends ListRecords
{
    protected static string $resource = FailedQueueJobResource::class;
}
