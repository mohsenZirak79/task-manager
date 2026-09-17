<?php

namespace App\Services;

use App\Models\OrgPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrgPositionService
{
    public function tree(): Collection
    {
        return OrgPosition::query()
            ->whereNull('parent_id')
            ->with(['users', 'children'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(OrgPosition $orgPosition): OrgPosition
    {
        return $orgPosition->load(['users', 'parent', 'children']);
    }

    public function create(array $data): OrgPosition
    {
        return DB::transaction(function () use ($data): OrgPosition {
            OrgPosition::query()->lockForUpdate()->get(['id']);

            if (($data['parent_id'] ?? null) === null && OrgPosition::query()->whereNull('parent_id')->exists()) {
                throw new HttpException(422, 'ایجاد بیشتر از یک جایگاه ریشه مجاز نیست.');
            }

            $userId = $data['user_id'] ?? null;
            unset($data['user_id']);

            $position = OrgPosition::query()->create($data);
            $this->syncAssignedUser($position, $userId);

            return $this->find($position);
        });
    }

    public function update(OrgPosition $orgPosition, array $data): OrgPosition
    {
        return DB::transaction(function () use ($orgPosition, $data): OrgPosition {
            OrgPosition::query()->lockForUpdate()->get(['id', 'parent_id']);

            if (array_key_exists('parent_id', $data)) {
                $this->ensureValidParent($orgPosition, $data['parent_id']);
            }

            $hasUser = array_key_exists('user_id', $data);
            $userId = $data['user_id'] ?? null;
            unset($data['user_id']);

            $orgPosition->update($data);
            if ($hasUser) {
                $this->syncAssignedUser($orgPosition, $userId);
            }

            return $this->find($orgPosition->fresh());
        });
    }

    public function delete(OrgPosition $orgPosition): void
    {
        DB::transaction(function () use ($orgPosition): void {
            $orgPosition = OrgPosition::query()->lockForUpdate()->findOrFail($orgPosition->id);

            if ($orgPosition->children()->exists()) {
                throw new HttpException(422, 'جایگاه دارای زیرمجموعه قابل حذف نیست.');
            }

            $orgPosition->delete();
        });
    }

    public function availableUsers(?string $search): Collection
    {
        return User::query()
            ->where('is_active', true)
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

    private function ensureValidParent(OrgPosition $position, ?int $parentId): void
    {
        if ($parentId === null) {
            if ($position->parent_id !== null && OrgPosition::query()->whereNull('parent_id')->whereKeyNot($position->id)->exists()) {
                throw new HttpException(422, 'ایجاد بیشتر از یک جایگاه ریشه مجاز نیست.');
            }

            return;
        }

        if ($parentId === $position->id) {
            throw new HttpException(422, 'یک جایگاه نمی‌تواند والد خودش باشد.');
        }

        $visited = [];
        $currentId = $parentId;
        while ($currentId !== null) {
            if ($currentId === $position->id || isset($visited[$currentId])) {
                throw new HttpException(422, 'انتقال جایگاه زیر یکی از زیرمجموعه‌های خودش و ایجاد چرخه مجاز نیست.');
            }
            $visited[$currentId] = true;
            $currentId = OrgPosition::query()->whereKey($currentId)->value('parent_id');
        }
    }

    private function syncAssignedUser(OrgPosition $position, ?int $userId): void
    {
        if ($userId !== null && ! User::query()->whereKey($userId)->where('is_active', true)->exists()) {
            throw new HttpException(422, 'کاربر فعال معتبری انتخاب نشده است.');
        }

        $position->users()->sync($userId === null ? [] : [$userId]);
    }
}
