<?php

namespace App\Filament\Resources\Operations\UniversalLdapTransferBatchResource\Pages;

use App\Filament\Resources\Operations\UniversalLdapTransferBatchResource;
use App\Models\Operations\UniversalLdapTransferBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class ViewUniversalLdapTransferBatch extends ViewRecord
{
    protected static string $resource = UniversalLdapTransferBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPlan')
                ->label('Download Plan')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->hasOutputFile())
                ->action(function () {
                    /** @var UniversalLdapTransferBatch $record */
                    $record = $this->getRecord();

                    if (! $record->hasOutputFile()) {
                        Notification::make()
                            ->title('Transfer plan file not found')
                            ->danger()
                            ->send();

                        return null;
                    }

                    return Response::download(
                        Storage::disk('local')->path((string) $record->output_path),
                        basename((string) $record->output_path),
                    );
                }),
        ];
    }
}
