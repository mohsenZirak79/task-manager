<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskVisibilityService;
use App\Support\Permissions;

class TaskPolicy
{
    public function __construct(private readonly TaskVisibilityService $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::TASKS_CREATE)
            && ($user->isSuperAdmin() || $user->orgPositions()->exists());
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW)
            && $this->visibility->canView($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_UPDATE)
            && in_array($task->status, [
                TaskStatus::Draft,
                TaskStatus::RevisionRequested,
                TaskStatus::Rejected,
            ], true)
            && ($this->visibility->canManageAll($user) || $this->isOwner($task, $user));
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_DELETE)
            && $task->status === TaskStatus::Draft
            && ($this->visibility->canManageAll($user) || $this->isOwner($task, $user));
    }

    public function submit(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::Draft && $this->update($user, $task);
    }

    public function approve(User $user, Task $task): bool
    {
        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_APPROVE);
    }

    public function reject(User $user, Task $task): bool
    {
        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_REJECT);
    }

    public function requestRevision(User $user, Task $task): bool
    {
        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_REJECT);
    }

    public function changeStatus(User $user, Task $task): bool
    {
        return $this->visibility->canChangeStatus($user, $task);
    }

    public function updateProgress(User $user, Task $task): bool
    {
        return $this->visibility->canUpdateProgress($user, $task);
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->is_active
            && $user->hasPermission(Permissions::TASKS_COMMENT)
            && $this->view($user, $task);
    }

    private function isOwner(Task $task, User $user): bool
    {
        return $task->created_by === $user->id || $task->requester_id === $user->id;
    }
}
