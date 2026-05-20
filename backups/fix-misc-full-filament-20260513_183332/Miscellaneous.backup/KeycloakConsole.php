<?php

namespace App\Filament\Pages\Miscellaneous;

use Filament\Actions\Action;
use Filament\Pages\Page;

class KeycloakConsole extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'Keycloak';

    protected static ?string $title = 'Keycloak Admin Console';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.miscellaneous.keycloak-console';

    public function getAdminConsoleUrl(): string
    {
        return (string) config('petra_iam.keycloak.admin_console_url');
    }

    public function getPublicUrl(): string
    {
        return (string) config('petra_iam.keycloak.public_url');
    }

    public function getRealm(): string
    {
        return (string) config('petra_iam.keycloak.realm');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openKeycloak')
                ->label('Open Keycloak Console')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => $this->getAdminConsoleUrl(), shouldOpenInNewTab: true),
        ];
    }
}
