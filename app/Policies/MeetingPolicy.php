<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;

class MeetingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
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
        return $user->hasPermission(Permissions::MEETINGS_UPDATE)
            && ($this->isAdmin($user) || $this->canManage($user, $meeting));
    }

    public function submit(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_DELETE)
            && ($this->isAdmin($user) || $meeting->created_by === $user->id);
    }

    public function complete(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_COMPLETE)
            && ($this->isAdmin($user) || $meeting->chairman_user_id === $user->id || $meeting->secretary_user_id === $user->id);
    }

    public function manageResolutions(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_MANAGE_RESOLUTIONS)
            && ($this->isAdmin($user) || $this->canManage($user, $meeting));
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
