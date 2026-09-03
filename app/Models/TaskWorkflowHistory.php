<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskWorkflowHistory extends Model
{
    protected $fillable = [
        'task_id', 'actor_id', 'action', 'from_status', 'to_status',
        'old_progress', 'new_progress', 'reason',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'from_status' => TaskStatus::class,
            'to_status' => TaskStatus::class,
            'old_progress' => 'integer',
            'new_progress' => 'integer',
        ];
    }
}
