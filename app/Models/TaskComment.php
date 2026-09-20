<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['task_id', 'user_id', 'parent_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->withTrashed();
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(TaskCommentReaction::class);
    }
}
