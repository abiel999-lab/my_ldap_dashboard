<?php

namespace App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;

use App\Filament\Resources\Operations\LdapTransferBatchResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateLdapTransferBatch extends CreateRecord
{
    protected static string $resource = LdapTransferBatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uuid'] = (string) Str::uuid();
        $data['status'] = 'draft';

        return $data;
    }
}
