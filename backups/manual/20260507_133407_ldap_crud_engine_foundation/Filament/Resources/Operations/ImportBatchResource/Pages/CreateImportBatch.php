<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateImportBatch extends CreateRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $path = $data['file_path'] ?? null;

        if ($path && Storage::disk('local')->exists($path)) {
            $data['file_size_bytes'] = Storage::disk('local')->size($path);
            $data['file_hash'] = hash_file('sha256', Storage::disk('local')->path($path));
            $data['original_filename'] = $data['original_filename'] ?: basename($path);
        }

        $data['status'] = $data['status'] ?? 'draft';
        $data['safe_mode'] = true;
        $data['preview_only'] = true;
        $data['destructive'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.import',
            'action' => 'create_import_batch',
            'status' => 'success',
            'target_type' => $this->record::class,
            'target_key' => (string) $this->record->id,
            'target_dn' => $this->record->base_dn,
            'ldap_connection_id' => $this->record->ldap_connection_id,
            'after_value' => $this->record->toArray(),
        ]);
    }
}
