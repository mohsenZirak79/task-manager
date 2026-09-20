<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCommentReaction extends Model
{
    protected $fillable = ['task_comment_id', 'user_id', 'reaction'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
