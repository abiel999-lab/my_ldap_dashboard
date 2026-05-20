<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use Filament\Resources\Pages\EditRecord;

class EditImportBatch extends EditRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $filePath = $data['file_path'] ?? '';

        if (is_array($filePath)) {
            $filePath = (string) reset($filePath);
            $data['file_path'] = $filePath;
        }

        $filePath = (string) $filePath;
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $data['import_type'] = match ($extension) {
                'csv' => 'csv',
                'ldif' => 'ldif',
                'json' => 'json',
                default => $data['import_type'] ?? 'csv',
            };
        }

        if (empty($data['original_filename']) && $filePath !== '') {
            $data['original_filename'] = basename($filePath);
        }

        if (array_key_exists('ldap_connection_id', $data) && blank($data['ldap_connection_id'])) {
            $data['ldap_connection_id'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
