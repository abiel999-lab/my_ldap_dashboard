<?php

namespace App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;

use App\Filament\Resources\Operations\LdapTransferBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLdapTransferBatch extends EditRecord
{
    protected static string $resource = LdapTransferBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview Transfer')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->requiresConfirmation()
                ->action(fn () => LdapTransferBatchResource::queueTransfer($this->record, 'preview')),

            Actions\Action::make('execute')
                ->label('Execute Transfer')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->requiresConfirmation()
                ->action(fn () => LdapTransferBatchResource::queueTransfer($this->record, 'execute')),

            Actions\DeleteAction::make(),
        ];
    }
}
