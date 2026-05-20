<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class Login extends BaseLogin
{
    public function mount(): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirect('/admin', navigate: false);

            return;
        }

        $this->redirectRoute('auth.keycloak.redirect', navigate: false);
    }

    public function getHeading(): string | Htmlable
    {
        return 'Petra Account';
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'Redirecting to Petra Keycloak single sign-on...';
    }
}
