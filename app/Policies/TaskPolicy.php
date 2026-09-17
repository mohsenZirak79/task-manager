<?php

namespace App\Policies;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Task;
use App\Models\User;
use App\Support\Permissions;

class TaskPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::TASKS_CREATE) && $user->orgPositions()->exists();
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW) && ($task->created_by === $user->id
            || $task->requester_id === $user->id
            || $task->financial_provider_user_id === $user->id
            || $task->equipment_provider_user_id === $user->id
            || $task->participantRecords()->where('user_id', $user->id)->exists());
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_UPDATE)
            && $task->created_by === $user->id
            && in_array($task->status, [
                TaskStatus::Draft,
                TaskStatus::RevisionRequested,
                TaskStatus::Rejected,
            ], true);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_DELETE)
            && $task->created_by === $user->id
            && $task->status === TaskStatus::Draft;
    }

    public function approve(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_APPROVE)
            && $this->canReviewRequest($user, $task);
    }

    public function reject(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_REJECT)
            && $this->canReviewRequest($user, $task);
    }

    public function requestRevision(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_REJECT)
            && $this->canReviewRequest($user, $task);
    }

    public function changeStatus(User $user, Task $task): bool
    {
        if (! $user->hasPermission(Permissions::TASKS_UPDATE_STATUS) || in_array($task->status, [
            TaskStatus::Draft,
            TaskStatus::PendingApproval,
            TaskStatus::RevisionRequested,
            TaskStatus::Rejected,
        ], true)) {
            return false;
        }

        return $task->created_by === $user->id
            || $task->participantRecords()
                ->where('user_id', $user->id)
                ->whereIn('role', [
                    TaskParticipantRole::Assignee->value,
                    TaskParticipantRole::Supervisor->value,
                ])->exists();
    }

    public function updateProgress(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)
            && $task->status === TaskStatus::InProgress
            && $this->hasParticipantRole($task, $user, TaskParticipantRole::Assignee);
    }

    private function hasParticipantRole(Task $task, User $user, TaskParticipantRole $role): bool
    {
        return $task->participantRecords()
            ->where('user_id', $user->id)
            ->where('role', $role->value)
            ->exists();
    }

    private function canReviewRequest(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::PendingApproval
            && $task->submission_type === TaskSubmissionType::Request
            && $this->hasParticipantRole($task, $user, TaskParticipantRole::Assignee);
    }
}
