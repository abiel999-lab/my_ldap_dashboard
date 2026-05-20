<?php

namespace App\Filament\Resources\Operations\QueueJobResource\Pages;

use App\Filament\Resources\Operations\QueueJobResource;
use Filament\Resources\Pages\ListRecords;

class ListQueueJobs extends ListRecords
{
    protected static string $resource = QueueJobResource::class;
}
