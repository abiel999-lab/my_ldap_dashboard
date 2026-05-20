<?php

namespace App\Filament\Resources\Directory\LdapEntryTypeRuleResource\Pages;

use App\Filament\Resources\Directory\LdapEntryTypeRuleResource;
use App\Services\Directory\LdapEntryTypeRegistryService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLdapEntryTypeRules extends ListRecords
{
    protected static string $resource = LdapEntryTypeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDefaults')
                ->label('Seed Default Rules')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Seed default LDAP entry type rules?')
                ->modalDescription('This creates or updates default dashboard type rules only. LDAP data will not be changed.')
                ->action(function (): void {
                    $result = app(LdapEntryTypeRegistryService::class)->seedDefaults();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Failed to seed default rules')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Default rules seeded')
                        ->body('Created: '.$result['created'].', Updated: '.$result['updated'].'. LDAP data was not changed.')
                        ->success()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
