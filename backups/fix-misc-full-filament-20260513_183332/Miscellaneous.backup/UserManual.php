<?php

namespace App\Filament\Pages\Miscellaneous;

use Filament\Pages\Page;

class UserManual extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'User Manual';

    protected static ?string $title = 'User Manual';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.miscellaneous.user-manual';

    public function getLdapDashboardUrl(): string
    {
        return (string) config('petra_iam.platform.ldap_dashboard_url');
    }

    public function getKeycloakUrl(): string
    {
        return (string) config('petra_iam.keycloak.public_url');
    }

    public function getKeycloakAdminConsoleUrl(): string
    {
        return (string) config('petra_iam.keycloak.admin_console_url');
    }

    public function getBaseDn(): string
    {
        return (string) config('petra_iam.platform.ldap_base_dn');
    }

    public function getKubernetesNamespace(): string
    {
        return (string) config('petra_iam.platform.kubernetes_namespace');
    }
}
