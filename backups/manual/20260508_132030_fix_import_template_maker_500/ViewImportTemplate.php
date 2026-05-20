<?php

namespace App\Filament\Resources\Operations\ImportTemplateResource\Pages;

use App\Filament\Resources\Operations\ImportTemplateResource;
use App\Models\Operations\ImportTemplate;
use App\Services\Operations\ImportTemplateGeneratorService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
                    /** @var ImportTemplate $record */
                    $record = $this->record;

                    $result = app(ImportTemplateGeneratorService::class)->generate($record);

                    Notification::make()
                        ->title(($result['ok'] ?? false) ? 'Template generated' : 'Generate failed')
                        ->body($result['message'] ?? 'Template generation finished.')
                        ->color(($result['ok'] ?? false) ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('download')
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->url(fn (): ?string => $this->record->download_url)
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => filled($this->record->output_path)),

            EditAction::make()
                ->label('Edit'),
        ];
    }
}
