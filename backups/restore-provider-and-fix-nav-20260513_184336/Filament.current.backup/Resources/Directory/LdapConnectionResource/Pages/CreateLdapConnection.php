<?php

namespace App\Filament\Resources\Directory\LdapConnectionResource\Pages;

use App\Filament\Resources\Directory\LdapConnectionResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateLdapConnection extends CreateRecord
{
    protected static string $resource = LdapConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (Auth::check()) {
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->logModelAction(
            module: 'directory.ldap_connections',
            action: 'create',
            status: 'success',
            target: $this->record,
            before: null,
            after: $this->record->fresh()?->toArray(),
        );
    }
}
