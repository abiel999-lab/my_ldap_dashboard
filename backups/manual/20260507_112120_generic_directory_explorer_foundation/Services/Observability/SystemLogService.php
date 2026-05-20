<?php

namespace App\Services\Observability;

use App\Models\Observability\SystemLogFile;
use Illuminate\Support\Facades\File;

class SystemLogService
{
    public function list(): array
    {
        return SystemLogFile::collectRows();
    }

    public function find(string $key): ?SystemLogFile
    {
        return SystemLogFile::findVirtual($key);
    }

    public function tail(string $key, int $lines = 120): string
    {
        $log = $this->find($key);

        if (! $log) {
            return 'Unknown log file.';
        }

        return $log->tail($lines);
    }

    public function clear(string $key): array
    {
        $log = $this->find($key);

        if (! $log) {
            return [
                'ok' => false,
                'message' => 'Unknown log file.',
                'path' => null,
                'size_before_bytes' => null,
            ];
        }

        $path = (string) $log->path;
        $sizeBefore = File::exists($path) ? File::size($path) : 0;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        return [
            'ok' => true,
            'message' => 'Log file cleared.',
            'path' => $path,
            'size_before_bytes' => $sizeBefore,
            'size_after_bytes' => File::size($path),
        ];
    }
}
