<?php

namespace App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;

use App\Filament\Resources\Operations\LdapTransferBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLdapTransferBatches extends ListRecords
{
    protected static string $resource = LdapTransferBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                    ->modalHeading('Create LDAP Transfer Preview')
                    ->modalSubmitActionLabel('Create Transfer')
                    ->modalWidth('7xl')
                    ->createAnother(false)
                ->label('New LDAP Transfer')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
