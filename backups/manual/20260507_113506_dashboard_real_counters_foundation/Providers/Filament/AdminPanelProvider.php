<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->login(Login::class)
            ->brandName('Petra LDAP Dashboard')
            ->brandLogo(asset('img/logo.png'))
            ->darkModeBrandLogo(asset('img/logo-light.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->viteTheme('resources/css/filament/petra/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->userMenuItems([
                'logout' => MenuItem::make()
                    ->label('Sign out')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->url('/auth/logout'),
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('1. Directory Management')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('2. Operations')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('3. Identity Federation')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('4. Network Access')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('5. Observability')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('6. Maintenance')
                    ->collapsible(),

                NavigationGroup::make()
                    ->label('7. Developer API / Integration')
                    ->collapsible(),
            ])
            ->colors([
                'primary' => Color::Blue,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'success' => Color::Green,
                'gray' => Color::Slate,
            ])
            ->darkMode(true)
            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
