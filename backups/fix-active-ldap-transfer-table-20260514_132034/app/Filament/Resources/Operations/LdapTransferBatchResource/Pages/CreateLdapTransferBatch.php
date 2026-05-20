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

        if (empty($data['ldap_filter'])) {
            $data['ldap_filter'] = '(objectClass=*)';
        }

        if (empty($data['scope'])) {
            $data['scope'] = 'base';
        }

        if (empty($data['source_input_mode'])) {
            $data['source_input_mode'] = 'dn_list';
        }

        if (empty($data['target_dn']) && ! empty($data['target_base_dn'])) {
            $data['target_dn'] = $data['target_base_dn'];
        }

        if (empty($data['target_base_dn']) && ! empty($data['target_dn'])) {
            $data['target_base_dn'] = $data['target_dn'];
        }

        return $data;
    }
}
