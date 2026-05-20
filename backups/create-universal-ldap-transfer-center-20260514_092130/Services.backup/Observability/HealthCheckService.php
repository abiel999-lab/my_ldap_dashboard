<?php

namespace App\Services\Observability;

use App\Models\Directory\LdapConnection;
use App\Models\Observability\HealthCheck;
use App\Services\Ldap\LdapConnectionHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthCheckService
{
    public function runAll(): array
    {
        return [
            $this->checkPostgres(),
            $this->checkQueueDatabase(),
            $this->checkStorage(),
            $this->checkLaravelLog(),
            ...$this->checkLdapConnections(),
        ];
    }

    public function checkPostgres(): HealthCheck
    {
        $startedAt = microtime(true);

        try {
            $databaseName = DB::connection()->getDatabaseName();
            $driver = DB::connection()->getDriverName();
            $result = DB::selectOne('select 1 as health_check');

            return $this->save(
                component: 'database',
                name: 'PostgreSQL',
                status: ((int) ($result->health_check ?? 0)) === 1 ? 'healthy' : 'failed',
                message: 'Database connection is reachable.',
                details: [
                    'driver' => $driver,
                    'database' => $databaseName,
                ],
                durationMs: $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->save(
                component: 'database',
                name: 'PostgreSQL',
                status: 'failed',
                message: $exception->getMessage(),
                details: [],
                durationMs: $this->durationMs($startedAt),
            );
        }
    }

    public function checkQueueDatabase(): HealthCheck
    {
        $startedAt = microtime(true);

        try {
            $jobsTableExists = Schema::hasTable('jobs');
            $failedJobsTableExists = Schema::hasTable('failed_jobs');

            $pendingJobs = $jobsTableExists ? DB::table('jobs')->count() : null;
            $failedJobs = $failedJobsTableExists ? DB::table('failed_jobs')->count() : null;

            $healthy = $jobsTableExists && $failedJobsTableExists;

            return $this->save(
                component: 'queue',
                name: 'Database Queue',
                status: $healthy ? 'healthy' : 'failed',
                message: $healthy
                    ? 'Queue database tables are available.'
                    : 'Queue database tables are missing.',
                details: [
                    'jobs_table_exists' => $jobsTableExists,
                    'failed_jobs_table_exists' => $failedJobsTableExists,
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs' => $failedJobs,
                    'queue_connection' => config('queue.default'),
                ],
                durationMs: $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->save(
                component: 'queue',
                name: 'Database Queue',
                status: 'failed',
                message: $exception->getMessage(),
                details: [],
                durationMs: $this->durationMs($startedAt),
            );
        }
    }

    public function checkStorage(): HealthCheck
    {
        $startedAt = microtime(true);

        try {
            $storagePath = storage_path();
            $logsPath = storage_path('logs');

            $storageWritable = is_writable($storagePath);
            $logsWritable = is_writable($logsPath);

            return $this->save(
                component: 'app',
                name: 'Storage',
                status: ($storageWritable && $logsWritable) ? 'healthy' : 'failed',
                message: ($storageWritable && $logsWritable)
                    ? 'Storage paths are writable.'
                    : 'One or more storage paths are not writable.',
                details: [
                    'storage_path' => $storagePath,
                    'storage_writable' => $storageWritable,
                    'logs_path' => $logsPath,
                    'logs_writable' => $logsWritable,
                ],
                durationMs: $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->save(
                component: 'app',
                name: 'Storage',
                status: 'failed',
                message: $exception->getMessage(),
                details: [],
                durationMs: $this->durationMs($startedAt),
            );
        }
    }

    public function checkLaravelLog(): HealthCheck
    {
        $startedAt = microtime(true);

        try {
            $logFile = storage_path('logs/laravel.log');
            $exists = File::exists($logFile);
            $sizeBytes = $exists ? File::size($logFile) : 0;

            return $this->save(
                component: 'app',
                name: 'Laravel Log',
                status: $exists ? 'healthy' : 'warning',
                message: $exists
                    ? 'Laravel log file exists.'
                    : 'Laravel log file does not exist yet.',
                details: [
                    'path' => $logFile,
                    'exists' => $exists,
                    'size_bytes' => $sizeBytes,
                    'size_mb' => round($sizeBytes / 1024 / 1024, 2),
                ],
                durationMs: $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->save(
                component: 'app',
                name: 'Laravel Log',
                status: 'failed',
                message: $exception->getMessage(),
                details: [],
                durationMs: $this->durationMs($startedAt),
            );
        }
    }

    public function checkLdapConnections(): array
    {
        $checks = [];

        LdapConnection::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->each(function (LdapConnection $connection) use (&$checks): void {
                $startedAt = microtime(true);

                try {
                    $result = app(LdapConnectionHealthService::class)->check($connection);

                    $connection->forceFill([
                        'last_health_checked_at' => now(),
                        'last_health_status' => $result['status'],
                        'last_health_message' => $result['message'].' Duration: '.$result['duration_ms'].'ms.',
                    ])->save();

                    $checks[] = $this->save(
                        component: 'ldap',
                        name: $connection->name,
                        status: $result['ok'] ? 'healthy' : 'failed',
                        message: $result['message'],
                        details: [
                            'ldap_connection_id' => $connection->id,
                            'host' => $connection->host,
                            'port' => $connection->port,
                            'base_dn' => $connection->base_dn,
                            'security' => $connection->security_mode,
                            'is_default' => $connection->is_default,
                        ],
                        durationMs: $result['duration_ms'] ?? $this->durationMs($startedAt),
                    );
                } catch (Throwable $exception) {
                    $checks[] = $this->save(
                        component: 'ldap',
                        name: $connection->name,
                        status: 'failed',
                        message: $exception->getMessage(),
                        details: [
                            'ldap_connection_id' => $connection->id,
                        ],
                        durationMs: $this->durationMs($startedAt),
                    );
                }
            });

        if ($checks === []) {
            $checks[] = $this->save(
                component: 'ldap',
                name: 'LDAP Connections',
                status: 'warning',
                message: 'No active LDAP connections found.',
                details: [],
                durationMs: 0,
            );
        }

        return $checks;
    }

    private function save(
        string $component,
        string $name,
        string $status,
        string $message,
        array $details,
        int $durationMs,
    ): HealthCheck {
        return HealthCheck::query()->updateOrCreate(
            [
                'component' => $component,
                'name' => $name,
            ],
            [
                'status' => $status,
                'message' => $message,
                'details' => $details,
                'duration_ms' => $durationMs,
                'checked_at' => now(),
            ],
        );
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
