<?php

namespace App\Filament\Resources\Directory\LdapEntryTypeRuleResource\Pages;

use App\Filament\Resources\Directory\LdapEntryTypeRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapEntryTypeRule extends ViewRecord
{
    protected static string $resource = LdapEntryTypeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
