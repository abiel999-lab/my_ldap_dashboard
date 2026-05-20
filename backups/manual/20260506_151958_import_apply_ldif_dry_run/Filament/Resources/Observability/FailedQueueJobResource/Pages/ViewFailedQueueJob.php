<?php

namespace App\Filament\Resources\Observability\FailedQueueJobResource\Pages;

use App\Filament\Resources\Observability\FailedQueueJobResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFailedQueueJob extends ViewRecord
{
    protected static string $resource = FailedQueueJobResource::class;
}
