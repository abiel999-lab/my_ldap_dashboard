<?php

namespace App\Services\Operations;

use App\Jobs\Operations\ExecuteLdapSearchScriptJob;
use App\Models\Operations\OperationJob;
use App\Models\Operations\SavedScript;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

class ScriptOperationDispatcher
{
    public function queueLdapSearch(SavedScript $script): OperationJob
    {
        $operationJob = app(OperationJobTracker::class)->create([
            'operation_type' => 'script_engine_ldapsearch',
            'type' => 'script_engine_ldapsearch',
            'name' => 'Execute ldapsearch Script - '.$script->name,
            'module' => 'operations.script_engine',
            'operation_action' => 'execute_ldapsearch_script',
            'action' => 'execute_ldapsearch_script',
            'status' => 'queued',
            'source' => 'filament',
            'target_type' => SavedScript::class,
            'target_key' => (string) $script->id,
            'target_dn' => null,
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'metadata' => [
                'source' => 'filament',
                'action' => 'execute_ldapsearch_script',
                'target_type' => SavedScript::class,
                'target_key' => (string) $script->id,
                'saved_script_id' => $script->id,
                'saved_script_name' => $script->name,
                'script_type' => $script->script_type,
                'queue' => 'script',
                'actor_user_id' => Auth::id(),
            ],
        ]);

        ExecuteLdapSearchScriptJob::dispatch(
            operationJobId: $operationJob->id,
            savedScriptId: $script->id,
        );

        app(AuditLogger::class)->log([
            'module' => 'operations.script_engine',
            'action' => 'queue_ldapsearch_script',
            'status' => 'success',
            'target_type' => SavedScript::class,
            'target_key' => (string) $script->id,
            'operation_job_id' => $operationJob->id,
            'request_payload' => [
                'saved_script_id' => $script->id,
                'saved_script_name' => $script->name,
                'script_type' => $script->script_type,
                'queue' => 'script',
            ],
        ]);

        return $operationJob;
    }
}
