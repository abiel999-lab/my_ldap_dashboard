<?php

namespace App\Jobs\Operations;

use App\Models\Operations\CommandExecution;
use App\Models\Operations\OperationJob;
use App\Models\Operations\SavedScript;
use App\Services\Operations\LdapSearchScriptExecutor;
use App\Services\Operations\OperationJobTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteLdapSearchScriptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $operationJobId,
        public int $savedScriptId,
    ) {
        $this->onQueue('script');
    }

    public function handle(
        OperationJobTracker $tracker,
        LdapSearchScriptExecutor $executor,
    ): void {
        $operationJob = OperationJob::query()->findOrFail($this->operationJobId);
        $script = SavedScript::query()->findOrFail($this->savedScriptId);

        $tracker->markRunning($operationJob);

        $item = $tracker->createItem($operationJob, [
            'target_type' => SavedScript::class,
            'target_identifier' => $script->name,
            'target_dn' => null,
            'action' => 'execute_ldapsearch_script',
            'status' => 'running',
            'input_payload' => [
                'saved_script_id' => $script->id,
                'saved_script_name' => $script->name,
                'script_type' => $script->script_type,
                'safe_mode_required' => $script->safe_mode_required,
                'preview_only' => $script->preview_only,
                'destructive' => $script->destructive,
            ],
            'payload_hash' => hash('sha256', json_encode([
                'saved_script_id' => $script->id,
                'script_body' => $script->script_body,
                'script_type' => $script->script_type,
            ])),
            'attempt_count' => 1,
            'started_at' => now(),
        ]);

        $tracker->log($operationJob, 'info', 'Executing ldapsearch script via queue.', [
            'saved_script_id' => $script->id,
            'saved_script_name' => $script->name,
            'script_type' => $script->script_type,
            'queue' => 'script',
        ], $item);

        $execution = $executor->execute($script);

        $execution->forceFill([
            'operation_job_id' => $operationJob->id,
            'operation_job_item_id' => $item?->id,
        ])->save();

        if ($execution->status !== 'success') {
            $tracker->updateItem($item, [
                'status' => 'failed',
                'output_payload' => [
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                    'error_message' => $execution->error_message,
                ],
                'error_message' => $execution->error_message ?: $execution->stderr ?: 'ldapsearch script failed.',
                'finished_at' => now(),
            ]);

            $tracker->markFailed($operationJob, $execution->error_message ?: 'ldapsearch script failed.', [
                'total_items' => 1,
                'processed_items' => 1,
                'success_items' => 0,
                'failed_items' => 1,
                'metadata' => [
                    'command_execution_id' => $execution->id,
                    'status' => $execution->status,
                    'exit_code' => $execution->exit_code,
                ],
            ]);

            return;
        }

        $tracker->updateItem($item, [
            'status' => 'success',
            'output_payload' => [
                'command_execution_id' => $execution->id,
                'status' => $execution->status,
                'exit_code' => $execution->exit_code,
                'duration_ms' => $execution->duration_ms,
            ],
            'finished_at' => now(),
        ]);

        $tracker->markSuccess($operationJob, [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'metadata' => [
                'command_execution_id' => $execution->id,
                'status' => $execution->status,
                'exit_code' => $execution->exit_code,
                'duration_ms' => $execution->duration_ms,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $tracker = app(OperationJobTracker::class);
        $operationJob = OperationJob::query()->find($this->operationJobId);

        if (! $operationJob) {
            return;
        }

        $tracker->markFailed($operationJob, $exception->getMessage(), [
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 0,
            'failed_items' => 1,
        ]);
    }
}
