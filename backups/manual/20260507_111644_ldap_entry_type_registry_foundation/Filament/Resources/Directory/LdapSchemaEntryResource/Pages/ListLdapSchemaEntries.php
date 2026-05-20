<?php

namespace App\Filament\Resources\Directory\LdapSchemaEntryResource\Pages;

use App\Filament\Resources\Directory\LdapSchemaEntryResource;
use App\Services\Directory\LdapSchemaSyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapSchemaEntries extends ListRecords
{
    protected static string $resource = LdapSchemaEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncSchema')
                ->label('Sync LDAP Schema')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync LDAP schema?')
                ->modalDescription('This reads cn=subschema and updates the local schema browser. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapSchemaSyncDispatcher::class)->queue();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to queue LDAP schema sync')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $operationJob = $result['operation_job'];

                    Notification::make()
                        ->title('LDAP schema sync queued')
                        ->body('Operation Job #'.$operationJob->id.' was created. LDAP data will not be changed.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
