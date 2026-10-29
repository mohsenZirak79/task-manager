<?php

namespace App\Policies;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;

class MeetingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['update', 'submit', 'start', 'acknowledge', 'sign', 'manageResolutions', 'complete'], true)) {
            return null;
        }

        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_CREATE);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_VIEW)
            && ($this->isAdmin($user) || $this->isMeetingMember($user, $meeting));
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->isEditableBeforeStart()
            && $user->hasPermission(Permissions::MEETINGS_UPDATE)
            && ($user->isSuperAdmin() || $this->isAdmin($user) || $this->canManage($user, $meeting));
    }

    public function submit(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function start(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Scheduled
            && $meeting->started_at === null
            && in_array($user->id, [$meeting->created_by, $meeting->secretary_user_id], true);
    }

    public function acknowledge(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Scheduled
            && $meeting->started_at === null
            && ! in_array($user->id, [$meeting->created_by, $meeting->secretary_user_id], true)
            && $this->isMeetingMember($user, $meeting);
    }

    public function sign(User $user, Meeting $meeting): bool
    {
        return $user->is_active
            && $meeting->status === MeetingStatus::Completed
            && $user->hasPermission(Permissions::MEETINGS_VIEW)
            && ($meeting->relationLoaded('attendees')
                ? $meeting->attendees->contains('id', $user->id)
                : $meeting->attendees()->whereKey($user->id)->exists());
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_DELETE)
            && ($this->isAdmin($user) || $meeting->created_by === $user->id);
    }

    public function complete(User $user, Meeting $meeting): bool
    {
        return $user->is_active && (
            $this->isOfficer($user, $meeting)
            || $user->isSuperAdmin()
            || ($user->hasPermission(Permissions::MEETINGS_COMPLETE) && $this->isAdmin($user))
        );
    }

    public function manageResolutions(User $user, Meeting $meeting): bool
    {
        return $user->is_active && (
            $this->isOfficer($user, $meeting)
            || $user->isSuperAdmin()
            || ($user->hasPermission(Permissions::MEETINGS_MANAGE_RESOLUTIONS)
                && ($this->isAdmin($user) || $this->canManage($user, $meeting)))
        );
    }

    private function isOfficer(User $user, Meeting $meeting): bool
    {
        return in_array($user->id, [$meeting->chairman_user_id, $meeting->secretary_user_id], true);
    }

    private function canManage(User $user, Meeting $meeting): bool
    {
        return $meeting->created_by === $user->id
            || $meeting->chairman_user_id === $user->id
            || $meeting->secretary_user_id === $user->id;
    }

    private function isMeetingMember(User $user, Meeting $meeting): bool
    {
        return $this->canManage($user, $meeting)
            || $meeting->attendees()->whereKey($user->id)->exists();
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasAccessRole(AccessRoles::ADMIN);
    }
}
