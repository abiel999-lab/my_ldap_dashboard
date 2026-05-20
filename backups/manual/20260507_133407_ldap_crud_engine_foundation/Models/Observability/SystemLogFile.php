<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class SystemLogFile extends Model
{
    public $timestamps = false;

    protected $table = 'system_log_files_virtual';

    protected $guarded = [];

    public function getKeyName(): string
    {
        return 'key';
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'exists' => 'boolean',
            'size_bytes' => 'integer',
            'size_mb' => 'float',
            'modified_at' => 'datetime',
        ];
    }

    public static function availableLogs(): array
    {
        return [
            'laravel' => [
                'key' => 'laravel',
                'name' => 'Laravel Application Log',
                'component' => 'app',
                'path' => storage_path('logs/laravel.log'),
                'description' => 'Main Laravel application error and warning log.',
            ],
            'queue_ldap' => [
                'key' => 'queue_ldap',
                'name' => 'LDAP Queue Worker Log',
                'component' => 'queue',
                'path' => storage_path('logs/queue-ldap.log'),
                'description' => 'Output log for LDAP queue worker.',
            ],
        ];
    }

    public static function collectRows(): array
    {
        return collect(self::availableLogs())
            ->map(function (array $definition): array {
                $path = $definition['path'];
                $exists = File::exists($path);
                $sizeBytes = $exists ? File::size($path) : 0;
                $modifiedAt = $exists ? date('Y-m-d H:i:s', File::lastModified($path)) : null;

                return array_merge($definition, [
                    'exists' => $exists,
                    'status' => $exists ? 'available' : 'missing',
                    'size_bytes' => $sizeBytes,
                    'size_mb' => round($sizeBytes / 1024 / 1024, 2),
                    'modified_at' => $modifiedAt,
                ]);
            })
            ->values()
            ->all();
    }

    public static function findVirtual(string $key): ?self
    {
        $row = collect(self::collectRows())
            ->firstWhere('key', $key);

        if (! $row) {
            return null;
        }

        $model = new self();
        $model->forceFill($row);
        $model->exists = true;

        return $model;
    }

    public function tail(int $lines = 120): string
    {
        $path = (string) $this->path;

        if (! File::exists($path)) {
            return 'Log file does not exist.';
        }

        $lines = max(10, min($lines, 500));

        $content = shell_exec('tail -n '.escapeshellarg((string) $lines).' '.escapeshellarg($path));

        return trim((string) $content) ?: 'Log file is empty.';
    }
}
