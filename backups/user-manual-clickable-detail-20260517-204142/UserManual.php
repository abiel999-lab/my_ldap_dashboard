<?php

namespace App\Filament\Pages\Miscellaneous;

use App\Filament\Widgets\UserManual\UserManualConceptsWidget;
use App\Filament\Widgets\UserManual\UserManualDirectoryWidget;
use App\Filament\Widgets\UserManual\UserManualOperationsWidget;
use App\Filament\Widgets\UserManual\UserManualOverviewWidget;
use App\Filament\Widgets\UserManual\UserManualSafetyWidget;
use App\Filament\Widgets\UserManual\UserManualTroubleshootingWidget;
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

    protected string $view = 'filament-panels::pages.page';

    public function getHeading(): string
    {
        return 'User Manual';
    }

    public function getSubheading(): ?string
    {
        return 'Petra LDAP Dashboard - panduan ringkas penggunaan fitur administrator.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserManualOverviewWidget::class,
            UserManualDirectoryWidget::class,
            UserManualOperationsWidget::class,
            UserManualSafetyWidget::class,
            UserManualConceptsWidget::class,
            UserManualTroubleshootingWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
