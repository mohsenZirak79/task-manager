<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskVisibilityService;
use App\Support\Permissions;

class TaskCommentPolicy
{
    public function __construct(private readonly TaskVisibilityService $visibility) {}

    public function viewAny(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW) && $this->visibility->canView($user, $task);
    }

    public function create(User $user, Task $task): bool
    {
        return $user->is_active
            && $user->hasPermission(Permissions::TASKS_COMMENT)
            && $this->viewAny($user, $task);
    }

    public function update(User $user, TaskComment $comment): bool
    {
        return ! $comment->trashed()
            && $comment->user_id === $user->id
            && $this->create($user, $comment->task);
    }

    public function delete(User $user, TaskComment $comment): bool
    {
        if ($comment->trashed() || ! $this->viewAny($user, $comment->task)) {
            return false;
        }

        return ($comment->user_id === $user->id && $user->hasPermission(Permissions::TASKS_COMMENT))
            || $user->hasPermission(Permissions::TASKS_MANAGE_COMMENTS);
    }

    public function reply(User $user, TaskComment $comment): bool
    {
        return ! $comment->trashed() && $this->create($user, $comment->task);
    }

    public function react(User $user, TaskComment $comment): bool
    {
        return ! $comment->trashed() && $this->create($user, $comment->task);
    }
}
