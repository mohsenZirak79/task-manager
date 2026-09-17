<?php

namespace App\Models;

use App\Enums\TaskParticipantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskParticipant extends Model
{
    protected $fillable = ['task_id', 'user_id', 'role'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['role' => TaskParticipantRole::class];
    }
}
