<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleService
{
    public function tree(): Collection
    {
        return Role::query()
            ->whereNull('parent_id')
            ->with(['user', 'children'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(Role $role): Role
    {
        return $role->load(['user', 'children']);
    }

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            Role::query()->lockForUpdate()->get(['id']);

            if (($data['parent_id'] ?? null) === null && Role::query()->whereNull('parent_id')->exists()) {
                throw new HttpException(422, 'ایجاد بیشتر از یک نقش ریشه مجاز نیست.');
            }

            $this->ensureUserIsAvailable($data['user_id'] ?? null);

            $role = Role::query()->create($data);

            return $this->find($role);
        });
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            Role::query()->lockForUpdate()->get(['id', 'parent_id', 'user_id']);

            if (array_key_exists('parent_id', $data)) {
                $this->ensureValidParent($role, $data['parent_id']);
            }

            if (array_key_exists('user_id', $data)) {
                $this->ensureUserIsAvailable($data['user_id'], $role->id);
            }

            $role->update($data);

            return $this->find($role->fresh());
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($role->children()->exists()) {
                throw new HttpException(422, 'نقش دارای زیرمجموعه قابل حذف نیست.');
            }

            $role->delete();
        });
    }

    public function availableUsers(?string $search, ?int $roleId): Collection
    {
        return User::query()
            ->where(function ($query) use ($roleId): void {
                $query->whereDoesntHave('role')
                    ->when($roleId, fn ($query) => $query->orWhereHas('role', fn ($query) => $query->whereKey($roleId)));
            })
            ->when($search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('org_code', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(50)
            ->get();
    }

    private function ensureValidParent(Role $role, ?int $parentId): void
    {
        if ($parentId === null) {
            if ($role->parent_id !== null && Role::query()->whereNull('parent_id')->whereKeyNot($role->id)->exists()) {
                throw new HttpException(422, 'ایجاد بیشتر از یک نقش ریشه مجاز نیست.');
            }

            return;
        }

        if ($parentId === $role->id) {
            throw new HttpException(422, 'یک نقش نمی‌تواند والد خودش باشد.');
        }

        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if ($currentId === $role->id || isset($visited[$currentId])) {
                throw new HttpException(422, 'انتقال نقش زیر یکی از زیرمجموعه‌های خودش و ایجاد چرخه مجاز نیست.');
            }

            $visited[$currentId] = true;
            $currentId = Role::query()->whereKey($currentId)->value('parent_id');
        }
    }

    private function ensureUserIsAvailable(?int $userId, ?int $exceptRoleId = null): void
    {
        if ($userId === null) {
            return;
        }

        $isAssigned = Role::query()
            ->where('user_id', $userId)
            ->when($exceptRoleId, fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();

        if ($isAssigned) {
            throw new HttpException(422, 'این کاربر قبلاً به نقش دیگری منصوب شده است.');
        }
    }
}
