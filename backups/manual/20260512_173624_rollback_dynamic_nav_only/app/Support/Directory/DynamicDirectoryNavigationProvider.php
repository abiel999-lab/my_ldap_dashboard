<?php

namespace App\Support\Directory;

use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Log;
use Throwable;

class DynamicDirectoryNavigationProvider
{
    public static function items(): array
    {
        try {
            $registry = app(DynamicEntryTypeRegistry::class);

            return collect($registry->navigationTypes())
                ->map(function (array $type): NavigationItem {
                    $key = (string) ($type['key'] ?? '');
                    $label = (string) ($type['label'] ?? $key);
                    $sort = (int) ($type['sort'] ?? 1000);

                    return NavigationItem::make($label)
                        ->group('1. Directory Management')
                        ->icon('heroicon-o-folder')
                        ->sort(500 + $sort)
                        ->url(url('/admin/directory/dynamic-entries/'.$key));
                })
                ->all();
        } catch (Throwable $e) {
            Log::error('DynamicDirectoryNavigationProvider failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
