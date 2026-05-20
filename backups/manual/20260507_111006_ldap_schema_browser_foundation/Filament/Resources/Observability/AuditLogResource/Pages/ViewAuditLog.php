<?php

namespace App\Filament\Resources\Observability\AuditLogResource\Pages;

use App\Filament\Resources\Observability\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;
}
