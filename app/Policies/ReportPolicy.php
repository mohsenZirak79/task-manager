<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;

class ReportPolicy
{
    public function before(User $user, string $ability): ?bool
    {
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

    public function view(User $user, Report $report): bool
    {
        if (! $user->hasPermission(Permissions::REPORTS_VIEW)) {
            return false;
        }
        if ($user->hasAccessRole(AccessRoles::ADMIN) || $report->created_by === $user->id || $report->recipient_user_id === $user->id) {
            return true;
        }
        if ($report->ccUsers()->whereKey($user->id)->exists()) {
            return true;
        }

        $meeting = $report->resolution?->meeting;
        if ($meeting === null) {
            return false;
        }

        return $meeting->created_by === $user->id
            || $meeting->chairman_user_id === $user->id
            || $meeting->secretary_user_id === $user->id
            || $meeting->attendees()->whereKey($user->id)->exists();
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
