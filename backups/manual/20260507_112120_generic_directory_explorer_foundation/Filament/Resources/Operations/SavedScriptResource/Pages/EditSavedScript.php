<?php

namespace App\Filament\Resources\Operations\SavedScriptResource\Pages;

use App\Filament\Resources\Operations\SavedScriptResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditSavedScript extends EditRecord
{
    protected static string $resource = SavedScriptResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->log([
            'module' => 'operations.script_engine',
            'action' => 'update_saved_script',
            'status' => 'success',
            'target_type' => $this->record::class,
            'target_key' => (string) $this->record->id,
            'after_value' => $this->record->toArray(),
        ]);
    }
}
