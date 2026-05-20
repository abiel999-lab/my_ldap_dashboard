<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

class QueueMonitorJob extends Model
{
    protected $guarded = [];

    protected $table = 'queue_monitor_jobs';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'reserved_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
