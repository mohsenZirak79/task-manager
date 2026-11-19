<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;

class ReportPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($ability === 'createTask') {
            return null;
        }

        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::REPORTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::REPORTS_MANAGE);
    }

    public function createTask(User $user, Report $report): bool
    {
        return $this->taskCreationBlockReason($user, $report) === null;
    }

    public function taskCreationBlockReason(User $user, Report $report): ?string
    {
        if (! $user->is_active) {
            return 'inactive_user';
        }
        if ($report->recipient_user_id !== $user->id) {
            return 'not_report_recipient';
        }
        if ($report->status !== ReportStatus::Viewed) {
            return match ($report->status) {
                ReportStatus::Draft => 'report_not_sent',
                ReportStatus::Sent => 'report_not_viewed',
                ReportStatus::Inactive => 'report_inactive',
                default => 'report_not_viewed',
            };
        }
        if (! $this->view($user, $report)) {
            return 'report_not_visible';
        }
        if (! Gate::forUser($user)->allows('create', Task::class)) {
            return 'task_creation_not_allowed';
        }

        return null;
    }

    public function view(User $user, Report $report): bool
    {
        if (! $user->hasPermission(Permissions::REPORTS_VIEW)) {
            return false;
        }
        if ($user->hasAccessRole(AccessRoles::ADMIN) || $report->created_by === $user->id || $report->recipient_user_id === $user->id) {
            return true;
        }
        if ($report->relationLoaded('ccUsers')
            ? $report->ccUsers->contains('id', $user->id)
            : $report->ccUsers()->whereKey($user->id)->exists()) {
            return true;
        }

        $meeting = $report->resolution?->meeting;
        if ($meeting === null) {
            return false;
        }

        return $meeting->created_by === $user->id
            || $meeting->chairman_user_id === $user->id
            || $meeting->secretary_user_id === $user->id
            || ($meeting->relationLoaded('attendees')
                ? $meeting->attendees->contains('id', $user->id)
                : $meeting->attendees()->whereKey($user->id)->exists());
    }

    public function update(User $user, Report $report): bool
    {
        return $user->hasPermission(Permissions::REPORTS_MANAGE) && $this->canManageMeeting($user, $report);
    }

    public function manageComment(User $user, Report $report, TaskComment $comment): bool
    {
        return $this->view($user, $report)
            && $comment->report_id === $report->id
            && ! $comment->trashed()
            && ($comment->user_id === $user->id
                || ($user->hasAccessRole(AccessRoles::ADMIN) && $user->hasPermission(Permissions::REPORTS_MANAGE)));
    }

    public function reactComment(User $user, Report $report, TaskComment $comment): bool
    {
        return $this->view($user, $report)
            && $comment->report_id === $report->id
            && ! $comment->trashed();
    }

    public function replyComment(User $user, Report $report, TaskComment $comment): bool
    {
        return $this->reactComment($user, $report, $comment);
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->update($user, $report);
    }

    private function canManageMeeting(User $user, Report $report): bool
    {
        if ($user->hasAccessRole(AccessRoles::ADMIN)) {
            return true;
        }
        $meeting = $report->resolution?->meeting;
        if ($meeting === null) {
            return $report->created_by === $user->id;
        }

        return $meeting->created_by === $user->id
            || $meeting->chairman_user_id === $user->id
            || $meeting->secretary_user_id === $user->id;
    }
}
