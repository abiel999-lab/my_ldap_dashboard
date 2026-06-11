<?php

namespace App\Filament\Resources\Operations\LdifExportBatchResource\Pages;

use App\Filament\Resources\Operations\LdifExportBatchResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ViewLdifExportBatch extends ViewRecord
{
    protected static string $resource = LdifExportBatchResource::class;


    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('download_ldif')
                ->label('Download LDIF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (): bool => $this->isDownloadActionVisible())
                ->url(fn () => $this->getDownloadUrl())
                ->openUrlInNewTab(),

            \Filament\Actions\Action::make('audit_view_file')
                ->label('Audit View File')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->isDownloadActionVisible())
                ->url(fn () => $this->getDownloadUrl())
                ->openUrlInNewTab(),
        ];
    }

    protected function isDownloadActionVisible(): bool
    {
        $status = strtolower((string) data_get($this->record, 'status', ''));

        return in_array($status, [
            'success',
            'succeeded',
            'completed',
            'complete',
            'done',
            'finished',
        ], true);
    }

    protected function getDownloadUrl(): string
    {
        return url('/admin/operations/ldif-export-batches/' . $this->record->getKey() . '/download');
    }


    protected function isSuccessfulExport(): bool
    {
        $status = strtolower((string) data_get($this->record, 'status', ''));

        return in_array($status, [
            'success',
            'succeeded',
            'completed',
            'complete',
            'done',
        ], true);
    }

    protected function buildAuditModalContent(): HtmlString
    {
        $path = $this->resolveLdifStoragePath();

        $html = '<div style="font-size: 13px; line-height: 1.6;">';
        $html .= '<div><strong>Export ID:</strong> ' . e((string) $this->record->getKey()) . '</div>';
        $html .= '<div><strong>Status:</strong> ' . e((string) data_get($this->record, 'status', '-')) . '</div>';

        if (! $path) {
            $html .= '<div style="margin-top: 12px; color: #f87171;"><strong>File LDIF tidak ditemukan.</strong></div>';
            $html .= '<div>Kemungkinan export berhasil secara status, tetapi output_path/file_path tidak tersimpan.</div>';
            $html .= '</div>';

            return new HtmlString($html);
        }

        $html .= '<div><strong>Storage Path:</strong> ' . e($path) . '</div>';

        try {
            $size = Storage::disk('local')->size($path);
            $html .= '<div><strong>File Size:</strong> ' . e(number_format($size)) . ' bytes</div>';

            $content = Storage::disk('local')->get($path);
            $lines = explode("\n", $content);
            $preview = implode("\n", array_slice($lines, 0, 120));

            $html .= '<div style="margin-top: 12px;"><strong>Preview first 120 lines:</strong></div>';
            $html .= '<pre style="margin-top: 8px; padding: 12px; overflow:auto; max-height:420px; background:#020617; border:1px solid #334155; border-radius:8px; color:#e5e7eb;">'
                . e($preview)
                . '</pre>';
        } catch (\Throwable $e) {
            $html .= '<div style="margin-top: 12px; color: #f87171;"><strong>Cannot read file:</strong> ' . e($e->getMessage()) . '</div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    protected function resolveLdifStoragePath(): ?string
    {
        $attributes = $this->record->getAttributes();

        $candidateKeys = [
            'output_path',
            'file_path',
            'export_path',
            'ldif_path',
            'path',
            'filename',
            'output_file',
            'output_file_path',
            'stored_file_path',
            'result_path',
            'download_path',
            'audit_file_path',
            'view_file_path',
        ];

        foreach ($candidateKeys as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $path = $this->normalizeStoragePath($attributes[$key]);

            if ($path && Storage::disk('local')->exists($path)) {
                return $path;
            }
        }

        foreach ($attributes as $value) {
            $path = $this->extractPathFromMixedValue($value);

            if ($path && Storage::disk('local')->exists($path)) {
                return $path;
            }
        }

        return $this->guessRecentExportFile();
    }

    protected function extractPathFromMixedValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $path = $this->extractPathFromMixedValue($item);

                if ($path) {
                    return $path;
                }
            }

            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (Str::startsWith($trimmed, ['{', '['])) {
                $decoded = json_decode($trimmed, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->extractPathFromMixedValue($decoded);
                }
            }

            if (Str::contains(Str::lower($trimmed), ['.ldif', 'ldif-export', 'ldif_exports', 'ldif-exports'])) {
                return $this->normalizeStoragePath($trimmed);
            }
        }

        return null;
    }

    protected function normalizeStoragePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);

        $storageApp = str_replace('\\', '/', storage_path('app'));
        $storagePrivate = str_replace('\\', '/', storage_path('app/private'));

        if (Str::startsWith($path, $storagePrivate)) {
            $path = 'private' . Str::after($path, $storagePrivate);
        } elseif (Str::startsWith($path, $storageApp)) {
            $path = ltrim(Str::after($path, $storageApp), '/');
        }

        $path = preg_replace('#^/+#', '', $path);
        $path = preg_replace('#^app/#', '', $path);
        $path = preg_replace('#^storage/app/#', '', $path);

        return $path ?: null;
    }

    protected function guessRecentExportFile(): ?string
    {
        $directories = [
            'private/ldif-exports',
            'private/ldif_exports',
            'private/exports',
            'private/ldap-exports',
            'private/operations/ldif-exports',
        ];

        $files = [];

        foreach ($directories as $directory) {
            if (! Storage::disk('local')->exists($directory)) {
                continue;
            }

            foreach (Storage::disk('local')->allFiles($directory) as $file) {
                if (Str::endsWith(Str::lower($file), '.ldif')) {
                    $files[] = $file;
                }
            }
        }

        if ($files === []) {
            return null;
        }

        usort($files, function (string $a, string $b): int {
            return Storage::disk('local')->lastModified($b) <=> Storage::disk('local')->lastModified($a);
        });

        return $files[0] ?? null;
    }
}
