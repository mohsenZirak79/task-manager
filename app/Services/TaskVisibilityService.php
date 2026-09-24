<?php

namespace App\Services;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TaskVisibilityService
{
    public const CREATED_BY_ME = 'created_by_me';

    public const ASSIGNED_TO_ME = 'assigned_to_me';

    public const INVOLVED = 'involved';

    public const ACTION_REQUIRED = 'action_required';

    public const ALL = 'all';

    public function resolveScope(?string $scope, ?string $submissionType): string
    {
        if ($scope) {
            return $scope;
        }

        return match ($submissionType) {
            TaskSubmissionType::Request->value => self::INVOLVED,
            TaskSubmissionType::Assignment->value => self::ASSIGNED_TO_ME,
            default => self::INVOLVED,
        };
    }

    public function apply(Builder $query, User $actor, string $scope): Builder
    {
        $actor->loadMissing('accessRoles.permissions');

        return match ($scope) {
            self::CREATED_BY_ME => $query->where(fn (Builder $query) => $query
                ->where('created_by', $actor->id)
                ->orWhere('requester_id', $actor->id)),
            self::ASSIGNED_TO_ME => $this->whereParticipant($query, $actor, [TaskParticipantRole::Assignee]),
            self::INVOLVED => $this->applyInvolved($query, $actor),
            self::ACTION_REQUIRED => $this->applyActionRequired($query, $actor),
            self::ALL => $this->applyAll($query, $actor),
            default => throw new HttpException(422, 'محدوده نمایش تسک معتبر نیست.'),
        };
    }

    public function canView(User $actor, Task $task): bool
    {
        if ($this->canManageAll($actor)) {
            return true;
        }

        if (in_array($actor->id, [
            $task->created_by,
            $task->requester_id,
            $task->financial_provider_user_id,
            $task->equipment_provider_user_id,
        ], true)) {
            return true;
        }

        return $task->relationLoaded('participantRecords')
            ? $task->participantRecords->contains('user_id', $actor->id)
            : $task->participantRecords()->where('user_id', $actor->id)->exists();
    }

    public function canManageAll(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasPermission(Permissions::TASKS_VIEW_ALL);
    }

    public function canReviewRequest(User $actor, Task $task, string $permission): bool
    {
        return $actor->hasPermission($permission)
            && $task->submission_type === TaskSubmissionType::Request
            && $this->hasParticipantRole($task, $actor, TaskParticipantRole::Assignee);
    }

    public function isAssignmentAssignee(User $actor, Task $task): bool
    {
        return $task->submission_type === TaskSubmissionType::Assignment
            && $this->hasParticipantRole($task, $actor, TaskParticipantRole::Assignee);
    }

    public function canChangeStatus(User $actor, Task $task): bool
    {
        return $actor->hasPermission(Permissions::TASKS_UPDATE_STATUS)
            && ($this->canManageAll($actor)
                || in_array($actor->id, [$task->created_by, $task->requester_id], true)
                || $this->hasParticipantRole($task, $actor, TaskParticipantRole::Assignee)
                || $this->hasParticipantRole($task, $actor, TaskParticipantRole::Supervisor));
    }

    public function canUpdateProgress(User $actor, Task $task): bool
    {
        return $actor->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)
            && $this->hasParticipantRole($task, $actor, TaskParticipantRole::Assignee);
    }

    private function applyInvolved(Builder $query, User $actor): Builder
    {
        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('created_by', $actor->id)
                ->orWhere('requester_id', $actor->id)
                ->orWhere('financial_provider_user_id', $actor->id)
                ->orWhere('equipment_provider_user_id', $actor->id)
                ->orWhereHas('participantRecords', fn (Builder $query) => $query->where('user_id', $actor->id));
        });
    }

    private function applyActionRequired(Builder $query, User $actor): Builder
    {
        $manageAll = $this->canManageAll($actor);

        return $query->where(function (Builder $actions) use ($actor, $manageAll): void {
            $hasAction = false;

            if ($actor->hasPermission(Permissions::TASKS_APPROVE)
                || $actor->hasPermission(Permissions::TASKS_REJECT)
                || $actor->hasPermission(Permissions::TASKS_UPDATE_STATUS)
                || $actor->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)) {
                $actions->where(function (Builder $query) use ($actor): void {
                    $query->where('submission_type', TaskSubmissionType::Assignment->value)
                        ->where(function (Builder $query) use ($actor): void {
                            $query->where(function (Builder $query) use ($actor): void {
                                $query->whereIn('status', [
                                    TaskStatus::PendingApproval->value,
                                    TaskStatus::ReadyToStart->value,
                                    TaskStatus::InProgress->value,
                                ])->whereHas('participantRecords', fn (Builder $query) => $query
                                    ->where('user_id', $actor->id)
                                    ->where('role', TaskParticipantRole::Assignee->value));
                            })->orWhere(function (Builder $query) use ($actor): void {
                                $query->where('status', TaskStatus::PendingCompletionApproval->value)
                                    ->where('created_by', $actor->id);
                            });
                        });
                });
                $hasAction = true;
            }

            if ($actor->hasPermission(Permissions::TASKS_APPROVE) || $actor->hasPermission(Permissions::TASKS_REJECT)) {
                $method = $hasAction ? 'orWhere' : 'where';
                $actions->{$method}(function (Builder $query) use ($actor): void {
                    $query->where('submission_type', TaskSubmissionType::Request->value)
                        ->where('status', TaskStatus::PendingApproval->value)
                        ->where(fn (Builder $query) => $this->whereParticipant(
                            $query,
                            $actor,
                            [TaskParticipantRole::Assignee],
                        ));
                });
                $hasAction = true;
            }

            if ($actor->hasPermission(Permissions::TASKS_UPDATE_STATUS)) {
                $method = $hasAction ? 'orWhere' : 'where';
                $actions->{$method}(function (Builder $query) use ($actor, $manageAll): void {
                    $query->where(function (Builder $query): void {
                        $query->whereNull('submission_type')
                            ->orWhere('submission_type', '!=', TaskSubmissionType::Assignment->value);
                    })->whereIn('status', [
                        TaskStatus::ReadyToStart->value,
                        TaskStatus::InProgress->value,
                        TaskStatus::NotCompleted->value,
                    ]);
                    if (! $manageAll) {
                        $query->where(function (Builder $query) use ($actor): void {
                            $query->where('created_by', $actor->id)
                                ->orWhere('requester_id', $actor->id)
                                ->orWhereHas('participantRecords', fn (Builder $query) => $query
                                    ->where('user_id', $actor->id)
                                    ->whereIn('role', [
                                        TaskParticipantRole::Assignee->value,
                                        TaskParticipantRole::Supervisor->value,
                                    ]));
                        });
                    }
                });
                $hasAction = true;
            }

            if ($actor->hasPermission(Permissions::TASKS_UPDATE_PROGRESS)) {
                $method = $hasAction ? 'orWhere' : 'where';
                $actions->{$method}(function (Builder $query) use ($actor): void {
                    $query->where(function (Builder $query): void {
                        $query->whereNull('submission_type')
                            ->orWhere('submission_type', '!=', TaskSubmissionType::Assignment->value);
                    })->where('status', TaskStatus::InProgress->value)
                        ->where(fn (Builder $query) => $this->whereParticipant(
                            $query,
                            $actor,
                            [TaskParticipantRole::Assignee],
                        ));
                });
                $hasAction = true;
            }

            if ($actor->hasPermission(Permissions::TASKS_UPDATE_STATUS)
                && ($actor->isSuperAdmin() || $actor->hasAccessRole(AccessRoles::ADMIN))) {
                $method = $hasAction ? 'orWhere' : 'where';
                $actions->{$method}(fn (Builder $query) => $query
                    ->where('submission_type', TaskSubmissionType::Request->value)
                    ->where('status', TaskStatus::Rejected->value));
                $hasAction = true;
            }

            if (! $hasAction) {
                $actions->whereRaw('1 = 0');
            }
        });
    }

    private function applyAll(Builder $query, User $actor): Builder
    {
        if (! $this->canManageAll($actor)) {
            throw new HttpException(403, 'دسترسی به همه تسک‌ها مجاز نیست.');
        }

        return $query;
    }

    private function hasParticipantRole(Task $task, User $actor, TaskParticipantRole $role): bool
    {
        if ($task->relationLoaded('participantRecords')) {
            return $task->participantRecords->contains(
                fn ($participant): bool => $participant->user_id === $actor->id && $participant->role === $role,
            );
        }

        return $task->participantRecords()
            ->where('user_id', $actor->id)
            ->where('role', $role->value)
            ->exists();
    }

    /** @param list<TaskParticipantRole> $roles */
    private function whereParticipant(Builder $query, User $actor, array $roles): Builder
    {
        return $query->whereHas('participantRecords', fn (Builder $query) => $query
            ->where('user_id', $actor->id)
            ->whereIn('role', array_map(fn (TaskParticipantRole $role) => $role->value, $roles)));
    }
}
