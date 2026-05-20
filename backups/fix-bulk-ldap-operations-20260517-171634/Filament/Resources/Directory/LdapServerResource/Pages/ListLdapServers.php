<?php

namespace App\Filament\Resources\Directory\LdapServerResource\Pages;

use App\Filament\Resources\Directory\LdapServerResource;
use App\Models\Directory\LdapServer;
use App\Services\Directory\LdapServerProvisioningService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLdapServers extends ListRecords
{
    protected static string $resource = LdapServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create LDAP Server')
                ->icon('heroicon-o-plus-circle')
                ->modalWidth('7xl')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data): array => LdapServerResource::mutateFormDataBeforeCreate($data))
                ->after(function (LdapServer $record): void {
                    app(LdapServerProvisioningService::class)->refreshGeneratedArtifacts($record);
                }),
        ];
    }
}
