<?php

namespace App\Support\Operations;

use App\Models\Operations\CommandExecution;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SafeCommandExecutionLogger
{
    public static function createQueued(string $commandType, string $command, array $context = []): ?CommandExecution
    {
        return self::create([
            'command_type' => $commandType,
            'status' => 'running',
            'command' => $command,
            'environment_context' => $context,
            'started_at' => now(),
        ]);
    }

    public static function createFailed(string $commandType, string $message, array $context = []): ?CommandExecution
    {
        return self::create([
            'command_type' => $commandType,
            'status' => 'failed',
            'command' => 'validation_or_dispatch_failed',
            'environment_context' => $context,
            'stderr' => $message,
            'error_message' => $message,
            'exit_code' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public static function markRunning(?int $id): void
    {
        self::update($id, [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public static function markSuccess(?int $id, mixed $stdout = null, array $context = []): void
    {
        self::update($id, [
            'status' => 'success',
            'exit_code' => 0,
            'stdout' => self::stringify($stdout),
            'stderr' => null,
            'error_message' => null,
            'environment_context' => $context === [] ? null : $context,
            'finished_at' => now(),
        ]);
    }

    public static function markPartial(?int $id, mixed $stdout = null, string $message = 'Partial failure.', array $context = []): void
    {
        self::update($id, [
            'status' => 'failed',
            'exit_code' => 1,
            'stdout' => self::stringify($stdout),
            'stderr' => $message,
            'error_message' => $message,
            'environment_context' => $context === [] ? null : $context,
            'finished_at' => now(),
        ]);
    }

    public static function markFailed(?int $id, string $message, mixed $stdout = null, array $context = []): void
    {
        self::update($id, [
            'status' => 'failed',
            'exit_code' => 1,
            'stdout' => self::stringify($stdout),
            'stderr' => $message,
            'error_message' => $message,
            'environment_context' => $context === [] ? null : $context,
            'finished_at' => now(),
        ]);
    }

    public static function create(array $data): ?CommandExecution
    {
        try {
            $payload = self::filterColumns($data);

            return CommandExecution::query()->create($payload);
        } catch (Throwable $e) {
            Log::error('SafeCommandExecutionLogger create failed', [
                'message' => $e->getMessage(),
                'data' => $data,
            ]);

            return null;
        }
    }

    public static function update(?int $id, array $data): void
    {
        if (! $id) {
            return;
        }

        try {
            $execution = CommandExecution::query()->find($id);

            if (! $execution) {
                return;
            }

            $payload = self::filterColumns($data);

            if (array_key_exists('environment_context', $payload) && $payload['environment_context'] === null) {
                unset($payload['environment_context']);
            }

            $execution->update($payload);
        } catch (Throwable $e) {
            Log::error('SafeCommandExecutionLogger update failed', [
                'message' => $e->getMessage(),
                'id' => $id,
                'data' => $data,
            ]);
        }
    }

    public static function id(?CommandExecution $execution): ?int
    {
        return $execution?->id;
    }

    public static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function filterColumns(array $data): array
    {
        try {
            $columns = Schema::getColumnListing((new CommandExecution())->getTable());

            return collect($data)
                ->filter(fn ($value, string $key): bool => in_array($key, $columns, true))
                ->all();
        } catch (Throwable) {
            return $data;
        }
    }
}
