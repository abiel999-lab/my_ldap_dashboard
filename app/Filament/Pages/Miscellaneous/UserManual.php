<?php

namespace App\Filament\Pages\Miscellaneous;

use App\Filament\Widgets\UserManual\UserManualDetailWidget;
use App\Filament\Widgets\UserManual\UserManualIndexWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use UnitEnum;

class UserManual extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'User Manual';

    protected static ?string $title = 'User Manual';

    protected static ?string $slug = 'user-manual/{section?}';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament-panels::pages.page';

    public ?string $section = null;

    public function mount(?string $section = null): void
    {
        $this->section = $section;
    }

    public function getHeading(): string
    {
        return $this->section
            ? $this->sectionTitle($this->section)
            : 'User Manual';
    }

    public function getSubheading(): ?string
    {
        return $this->section
            ? 'Panduan detail penggunaan fitur administrator.'
            : 'Klik salah satu kartu manual untuk membuka penjelasan detail.';
    }

    protected function getHeaderActions(): array
    {
        if (! $this->section) {
            return [];
        }

        return [
            Action::make('back')
                ->label('Kembali ke Manual')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::getUrl()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            $this->section ? UserManualDetailWidget::class : UserManualIndexWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    private function sectionTitle(string $section): string
    {
        return match ($section) {
            'pengantar' => '1. Pengantar Aplikasi',
            'dashboard' => '2. Dashboard',
            'directory' => '3. Directory Management',
            'connections' => '4. LDAP Connections',
            'operations' => '5. Operations',
            'bulk' => '6. LDAP Bulk Operations',
            'import-export' => '7. Import, Export, Transfer, Sync',
            'observability' => '8. Observability',
            'concepts' => '9. LDAP Concepts',
            'troubleshooting' => '10. Troubleshooting',
            'sop' => '11. SOP Administrator',
            default => 'User Manual',
        };
    }
}
