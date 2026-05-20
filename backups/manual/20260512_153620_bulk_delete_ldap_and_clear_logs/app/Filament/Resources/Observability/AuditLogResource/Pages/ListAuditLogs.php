<?php

namespace App\Filament\Resources\Observability\AuditLogResource\Pages;

use App\Filament\Resources\Observability\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
