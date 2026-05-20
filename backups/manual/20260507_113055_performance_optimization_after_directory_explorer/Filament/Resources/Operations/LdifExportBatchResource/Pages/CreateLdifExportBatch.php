<?php

namespace App\Filament\Resources\Operations\LdifExportBatchResource\Pages;

use App\Filament\Resources\Operations\LdifExportBatchResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateLdifExportBatch extends CreateRecord
{
    protected static string $resource = LdifExportBatchResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.export',
            'action' => 'create_ldif_export_batch',
            'status' => 'success',
            'target_type' => $this->record::class,
            'target_key' => (string) $this->record->id,
            'target_dn' => $this->record->base_dn,
            'ldap_connection_id' => $this->record->ldap_connection_id,
            'after_value' => $this->record->toArray(),
        ]);
    }
}
