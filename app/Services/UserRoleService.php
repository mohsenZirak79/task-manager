<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserRoleService
{
    public function all(User $user): Collection
    {
        return $user->userRoles()->with('role')->latest()->get();
    }

    public function attach(User $user, array $data): UserRole
    {
        return DB::transaction(function () use ($user, $data) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = Role::query()->lockForUpdate()->findOrFail($data['role_id']);
            if (! $lockedUser->is_active) {
                throw ValidationException::withMessages(['user_id' => 'کاربر غیرفعال است.']);
            } if (! $role->is_active) {
                throw ValidationException::withMessages(['role_id' => 'نقش غیرفعال است.']);
            } if (UserRole::query()->whereBelongsTo($lockedUser)->whereBelongsTo($role)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['role_id' => 'این نقش فعال قبلاً به کاربر متصل شده است.']);
            } $data['user_id'] = $lockedUser->id;
            $data['is_active'] = $data['is_active'] ?? true;

            return UserRole::query()->create($data)->load('role');
        });
    }

    public function deactivate(User $user, Role $role): UserRole
    {
        return DB::transaction(function () use ($user, $role) {
            $assignment = $this->latest($user, $role, true);
            $assignment->update(['is_active' => false, 'ended_at' => now()]);

            return $assignment->fresh('role');
        });
    }

    public function activate(User $user, Role $role): UserRole
    {
        return DB::transaction(function () use ($user, $role) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->id);
            if (! $lockedUser->is_active) {
                throw ValidationException::withMessages(['user_id' => 'کاربر غیرفعال است.']);
            } if (! $lockedRole->is_active) {
                throw ValidationException::withMessages(['role_id' => 'نقش غیرفعال است.']);
            } if (UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['role_id' => 'این نقش هم‌اکنون برای کاربر فعال است.']);
            } $assignment = $this->latest($user, $role, false);
            $assignment->update(['is_active' => true, 'ended_at' => null]);

            return $assignment->fresh('role');
        });
    }

    private function latest(User $user, Role $role, ?bool $active): UserRole
    {
        $q = UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id);
        if ($active !== null) {
            $q->where('is_active', $active);
        } $item = $q->latest('id')->lockForUpdate()->first();
        if (! $item) {
            throw ValidationException::withMessages(['role_id' => 'این نقش با وضعیت موردنظر به کاربر متصل نیست.']);
        }

return $item;
    }
}
