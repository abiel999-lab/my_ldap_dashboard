<?php

namespace App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;

use App\Filament\Resources\Operations\BulkLdapOperationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBulkLdapOperation extends CreateRecord
{
    protected static string $resource = BulkLdapOperationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::user()?->email ?? Auth::user()?->name ?? 'system';
        $data['status'] = $data['status'] ?? 'draft';

        return $data;
    }
}
