<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Task;
use App\Models\TaskPlanningItem;
use App\Models\User;
use App\Services\TaskTeamService;
use App\Services\TaskVisibilityService;
use App\Support\AccessRoles;
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
            && ($user->isSuperAdmin() || ($user->relationLoaded('orgPositions')
                ? $user->orgPositions->isNotEmpty()
                : $user->orgPositions()->exists()));
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_VIEW)
            && $this->visibility->canView($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        if (! $user->hasPermission(Permissions::TASKS_UPDATE)) {
            return false;
        }

        if ($task->submission_type === TaskSubmissionType::Assignment) {
            return in_array($task->status, [TaskStatus::Draft, TaskStatus::RevisionRequested], true)
                && $task->created_by === $user->id
                && $task->requester_id === $user->id;
        }

        return in_array($task->status, [
            TaskStatus::Draft,
            TaskStatus::RevisionRequested,
            TaskStatus::Rejected,
        ], true)
            && $this->isOwner($task, $user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_DELETE)
            && $task->status === TaskStatus::Draft
            && ($this->visibility->canManageAll($user) || $this->isOwner($task, $user));
    }

    public function submit(User $user, Task $task): bool
    {
        $allowedStatuses = $task->submission_type === TaskSubmissionType::Assignment
            ? [TaskStatus::Draft, TaskStatus::RevisionRequested]
            : [TaskStatus::Draft, TaskStatus::RevisionRequested, TaskStatus::Rejected];

        return in_array($task->status, $allowedStatuses, true) && $this->update($user, $task);
    }

    public function approve(User $user, Task $task): bool
    {
        if ($task->transferred_at) {
            return false;
        }
        if ($task->submission_type === TaskSubmissionType::Assignment) {
            return $user->hasPermission(Permissions::TASKS_APPROVE)
                && $this->visibility->isAssignmentAssignee($user, $task);
        }

        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_APPROVE);
    }

    public function reject(User $user, Task $task): bool
    {
        if ($task->transferred_at) {
            return false;
        }

        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_REJECT);
    }

    public function requestRevision(User $user, Task $task): bool
    {
        if ($task->transferred_at) {
            return false;
        }
        if ($task->submission_type === TaskSubmissionType::Assignment) {
            return $user->hasPermission(Permissions::TASKS_REJECT)
                && $this->visibility->isAssignmentAssignee($user, $task);
        }

        return $this->visibility->canReviewRequest($user, $task, Permissions::TASKS_REJECT);
    }

    public function changeStatus(User $user, Task $task): bool
    {
        if ($task->transferred_at || ($task->relationLoaded('teamMembers') ? $task->teamMembers->isNotEmpty() : $task->teamMembers()->exists())) {
            return $user->hasPermission(Permissions::TASKS_UPDATE_STATUS)
                && app(TaskTeamService::class)->isLeader($user, $task)
                && (! $task->transferred_at || $task->assignee_viewed_at !== null);
        }
        if ($task->submission_type === TaskSubmissionType::Assignment) {
            return $user->hasPermission(Permissions::TASKS_UPDATE_STATUS)
                && $this->visibility->isAssignmentAssignee($user, $task);
        }

        return $this->visibility->canChangeStatus($user, $task);
    }

    public function close(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_UPDATE_STATUS)
            && ($user->isSuperAdmin() || $user->hasAccessRole(AccessRoles::ADMIN))
            && $task->submission_type === TaskSubmissionType::Request
            && $task->status === TaskStatus::Rejected;
    }

    public function updateProgress(User $user, Task $task): bool
    {
        if ($task->transferred_at && $task->assignee_viewed_at === null) {
            return false;
        }
        if ($task->submission_type === TaskSubmissionType::Assignment) {
            return $user->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)
                && $this->visibility->isAssignmentAssignee($user, $task);
        }

        return $this->visibility->canUpdateProgress($user, $task);
    }

    public function updatePlanning(User $user, Task $task): bool
    {
        if ($task->transferred_at && $task->assignee_viewed_at === null) {
            return false;
        }

        return in_array($task->status, [TaskStatus::ReadyToStart, TaskStatus::InProgress], true)
            && $user->hasPermission(Permissions::TASKS_UPDATE)
            && $this->visibility->canUpdateProgress($user, $task);
    }

    public function updatePlanningProgress(User $user, Task $task, ?TaskPlanningItem $item = null): bool
    {
        if ($item && $item->task_id !== $task->id) {
            return false;
        }
        if ($this->visibility->canUpdateProgress($user, $task)) {
            return ! $task->transferred_at || $task->assignee_viewed_at !== null;
        }

        return $user->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)
            && $task->status === TaskStatus::InProgress
            && app(TaskTeamService::class)->isMember($user, $task)
            && $task->teamMembers->firstWhere('id', $user->id)?->pivot->viewed_at !== null
            && ($item ? $item->assignee_user_id === $user->id : $task->planningItems()->where('assignee_user_id', $user->id)->exists());
    }

    public function acknowledge(User $user, Task $task): bool
    {
        return app(TaskTeamService::class)->mayAcknowledge($user, $task);
    }

    public function transfer(User $user, Task $task): bool
    {
        return $task->status === TaskStatus::PendingApproval && $task->transferred_at === null
            && $user->hasPermission(Permissions::TASKS_APPROVE)
            && app(TaskTeamService::class)->isLeader($user, $task);
    }

    public function manageTeam(User $user, Task $task): bool
    {
        return $user->hasPermission(Permissions::TASKS_UPDATE)
            && (! $task->transferred_at || $task->assignee_viewed_at !== null)
            && in_array($task->status, [TaskStatus::PendingApproval, TaskStatus::ReadyToStart, TaskStatus::InProgress], true)
            && app(TaskTeamService::class)->isLeader($user, $task);
    }

    public function requestCompletion(User $user, Task $task): bool
    {
        return $task->submission_type === TaskSubmissionType::Assignment
            && $user->hasPermission(Permissions::TASKS_UPDATE_STATUS)
            && $this->visibility->isAssignmentAssignee($user, $task);
    }

    public function approveCompletion(User $user, Task $task): bool
    {
        return $task->submission_type === TaskSubmissionType::Assignment
            && $user->hasPermission(Permissions::TASKS_APPROVE)
            && $task->created_by === $user->id;
    }

    public function rejectCompletion(User $user, Task $task): bool
    {
        return $task->submission_type === TaskSubmissionType::Assignment
            && $user->hasPermission(Permissions::TASKS_REJECT)
            && $task->created_by === $user->id;
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->is_active
            && $user->hasPermission(Permissions::TASKS_COMMENT)
            && $this->visibility->canViewDirectly($user, $task)
            && $this->view($user, $task);
    }

    private function isOwner(Task $task, User $user): bool
    {
        return $task->created_by === $user->id || $task->requester_id === $user->id;
    }
}
