<?php

namespace App\Filament\Resources\Directory\LdapEntryTypeRuleResource\Pages;

use App\Filament\Resources\Directory\LdapEntryTypeRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLdapEntryTypeRule extends EditRecord
{
    protected static string $resource = LdapEntryTypeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->is_system),
        ];
    }
}
