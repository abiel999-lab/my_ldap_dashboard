<?php

namespace App\Filament\Resources\Operations\LdapTransferBatchResource\Pages;

use Filament\Schemas\Components\Section;

use Filament\Forms\Components\Toggle;

use Filament\Forms\Components\Textarea;

use Filament\Forms\Components\TextInput;

use Filament\Forms\Components\Select;

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
                    ->modalWidth('5xl')
                    
                    ->modalSubmitActionLabel('Create Transfer')
                    ->modalWidth('5xl')
                    
                    ->createAnother(false)
                ->label('New LDAP Transfer')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
