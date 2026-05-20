<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\Pages;

use App\Filament\Resources\Operations\ImportBatchResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

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

        if ($filePath !== '') {
            if (! in_array($extension, ['csv', 'ldif', 'json'], true)) {
                throw ValidationException::withMessages([
                    'file_path' => 'Only .csv, .ldif, and .json import files are allowed.',
                ]);
            }

            $data['import_type'] = match ($extension) {
                'csv' => 'csv',
                'ldif' => 'ldif',
                'json' => 'json',
            };

            if (empty($data['original_filename'])) {
                $data['original_filename'] = basename($filePath);
            }
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
