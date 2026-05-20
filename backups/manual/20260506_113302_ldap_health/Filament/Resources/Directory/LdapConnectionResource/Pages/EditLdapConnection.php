<?php

namespace App\Filament\Resources\Directory\LdapConnectionResource\Pages;

use App\Filament\Resources\Directory\LdapConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditLdapConnection extends EditRecord
{
    protected static string $resource = LdapConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (Auth::check()) {
            $data['updated_by'] = Auth::id();
        }

        return $data;
    }
}
