<?php

namespace App\Filament\Resources\Directory\LdapUserEntryResource\Pages;

use App\Filament\Resources\Directory\LdapUserEntryResource;
use App\Services\Ldap\LdapEntryInspectorService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLdapUserEntry extends ViewRecord
{
    protected static string $resource = LdapUserEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attributeStudio')
                ->label('Attribute Studio')
                ->icon('heroicon-o-table-cells')
                ->color('info')
                ->modalHeading('LDAP User Attribute Studio')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.ldap.attribute-studio', [
                    'inspection' => app(LdapEntryInspectorService::class)->inspect($this->record),
                ])),

            EditAction::make(),
        ];
    }
}
