<?php

namespace App\Filament\Pages\Miscellaneous;

use Filament\Actions\Action;
use Filament\Pages\Page;

class KeycloakConsole extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'Keycloak';

    protected static ?string $title = 'Keycloak';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament-panels::pages.page';

    public function getHeading(): string
    {
        return 'Keycloak';
    }

    public function getSubheading(): ?string
    {
        return 'Open the Keycloak Admin Console from this page.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openKeycloakConsole')
                ->label('Open Keycloak Admin Console')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(
                    url: config('petra_iam.keycloak.admin_console_url', 'https://auth-ldap.ppsi.petra.ac.id/admin/master/console/'),
                    shouldOpenInNewTab: true
                ),
        ];
    }
}
