<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use Filament\Resources\Pages\EditRecord;

class EditImportBatch extends EditRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $filePath = (string) ($data['file_path'] ?? '');

        if (is_array($data['file_path'] ?? null)) {
            $filePath = (string) reset($data['file_path']);
            $data['file_path'] = $filePath;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($filePath !== '') {
            $data['import_type'] = match ($extension) {
                'csv' => 'csv',
                'json' => 'json',
                'ldif' => 'ldif',
                default => $data['import_type'] ?? 'csv',
            };

            if (empty($data['original_filename'])) {
                $data['original_filename'] = basename($filePath);
            }
        }

        if (array_key_exists('ldap_connection_id', $data) && (int) $data['ldap_connection_id'] === 0) {
            $data['ldap_connection_id'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
