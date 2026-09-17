<?php

namespace App\Policies;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin && $ability !== 'update' ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role()->exists();
    }

    public function create(User $user): bool
    {
        return $user->role()->exists();
    }

    public function view(User $user, Task $task): bool
    {
        return $task->created_by === $user->id
            || $task->requester_id === $user->id
            || $task->financial_provider_user_id === $user->id
            || $task->equipment_provider_user_id === $user->id
            || $task->participantRecords()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Task $task): bool
    {
        return $task->created_by === $user->id
            && in_array($task->status, [
                TaskStatus::Draft,
                TaskStatus::RevisionRequested,
                TaskStatus::Rejected,
            ], true);
    }

    public function approve(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::PendingApproval
            && $task->submission_type === TaskSubmissionType::Request
            && $this->hasParticipantRole($task, $user, TaskParticipantRole::Assignee);
    }

    public function reject(User $user, Task $task): bool
    {
        return $this->approve($user, $task);
    }

    public function requestRevision(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::PendingApproval
            && $task->submission_type === TaskSubmissionType::Request
            && $this->hasParticipantRole($task, $user, TaskParticipantRole::Assignee);
    }

    public function changeStatus(User $user, Task $task): bool
    {
        if (in_array($task->status, [
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
        return $task->status === TaskStatus::InProgress
            && $this->hasParticipantRole($task, $user, TaskParticipantRole::Assignee);
    }

    private function hasParticipantRole(Task $task, User $user, TaskParticipantRole $role): bool
    {
        return $task->participantRecords()
            ->where('user_id', $user->id)
            ->where('role', $role->value)
            ->exists();
    }
}
