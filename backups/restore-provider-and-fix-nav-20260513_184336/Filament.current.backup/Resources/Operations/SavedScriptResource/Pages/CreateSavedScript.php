<?php

namespace App\Filament\Resources\Operations\SavedScriptResource\Pages;

use App\Filament\Resources\Operations\SavedScriptResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateSavedScript extends CreateRecord
{
    protected static string $resource = SavedScriptResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.script_engine',
            'action' => 'create_saved_script',
            'status' => 'success',
            'target_type' => $this->record::class,
            'target_key' => (string) $this->record->id,
            'after_value' => $this->record->toArray(),
        ]);
    }


    public static function canAccess(array $parameters = []): bool
    {
        return false;
    }
}
