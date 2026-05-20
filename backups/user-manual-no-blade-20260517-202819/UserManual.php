<?php

namespace App\Filament\Pages\Miscellaneous;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class UserManual extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'User Manual';

    protected static ?string $title = 'User Manual';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.miscellaneous.user-manual';

    public function getHeading(): string
    {
        return 'User Manual';
    }

    public function getSubheading(): ?string
    {
        return 'Petra LDAP Dashboard - User Manual Lengkap';
    }
}
