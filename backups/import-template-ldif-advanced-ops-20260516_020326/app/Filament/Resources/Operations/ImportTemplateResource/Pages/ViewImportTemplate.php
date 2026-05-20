<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use App\Services\Operations\SmartImportTemplateResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ViewImportTemplate extends ViewRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateTemplate')
                ->label('Generate Template')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('success')
                ->action(function (): void {
                    $record = $this->getRecord();

                    $path = app(SmartImportTemplateResolver::class)->storeTemplateFile($record);

                    $this->updateRecordIfColumnsExist($record, [
                        'output_path' => $path,
                        'status' => 'generated',
                    ]);

                    Notification::make()
                        ->title('Template generated')
                        ->body('Smart template regenerated from LDAP schema rules.')
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('view', [
                        'record' => $record->getKey(),
                    ]));
                }),

            Action::make('downloadTemplate')
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->action(function () {
                    $record = $this->getRecord();

                    $path = app(SmartImportTemplateResolver::class)->storeTemplateFile($record);

                    $this->updateRecordIfColumnsExist($record, [
                        'output_path' => $path,
                        'status' => 'generated',
                    ]);

                    $metadata = $record->metadata ?? [];

                    if (is_string($metadata)) {
                        $decoded = json_decode($metadata, true);
                        $metadata = is_array($decoded) ? $decoded : [];
                    }

                    $format = strtolower((string) ($metadata['file_format'] ?? $record->file_format ?? 'csv'));

                    $extension = match ($format) {
                        'ldif' => 'ldif',
                        'json' => 'json',
                        default => 'csv',
                    };

                    $contentType = match ($format) {
                        'ldif' => 'text/plain',
                        'json' => 'application/json',
                        default => 'text/csv',
                    };

                    $filename = str($record->name ?? 'ldap-import-template')
                        ->slug()
                        ->append('.'.$extension)
                        ->toString();

                    return response()->download(
                        Storage::disk('local')->path($path),
                        $filename,
                        ['Content-Type' => $contentType]
                    );
                }),

            Action::make('editTemplate')
                ->label('Edit')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->url(fn () => static::getResource()::getUrl('edit', [
                    'record' => $this->getRecord()->getKey(),
                ])),
        ];
    }

    private function updateRecordIfColumnsExist($record, array $data): void
    {
        $columns = Schema::getColumnListing($record->getTable());

        $clean = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $clean[$key] = $value;
            }
        }

        if ($clean !== []) {
            $record->forceFill($clean)->save();
        }
    }
}
