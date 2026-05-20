<?php

namespace App\Filament\Resources\Operations\LdapSyncBatchResource\Pages;

use App\Filament\Resources\Operations\LdapSyncBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListLdapSyncBatches extends ListRecords
{
    protected static string $resource = LdapSyncBatchResource::class;
}
