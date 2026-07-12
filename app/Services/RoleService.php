<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleRelation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $active = filter_var($filters['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return Role::query()->when($filters['search'] ?? null, function ($q, $s) {
            $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"));
        })->when($active !== null, fn ($q) => $q->where('is_active', $active))->when(isset($filters['level']), fn ($q) => $q->where('level', $filters['level']))->latest()->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));
    }

    public function create(array $data): Role
    {
        return DB::transaction(fn () => Role::query()->create($data));
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            if (isset($data['level']) && (int) $data['level'] !== $role->level) {
                $this->validateNewLevel($role, (int) $data['level']);
            } $role->update($data);

            return $role->fresh();
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(fn () => $role->delete());
    }

    public function activate(Role $role): Role
    {
        $role->update(['is_active' => true]);

        return $role->fresh();
    }

    public function deactivate(Role $role): Role
    {
        $role->update(['is_active' => false]);

        return $role->fresh();
    }

    private function validateNewLevel(Role $role, int $level): void
    {
        foreach ($role->parentRelations()->with('childRole')->get() as $r) {
            $this->assertLevels($level, $r->childRole->level, $r->relation_type);
        }
        foreach ($role->childRelations()->with('parentRole')->get() as $r) {
            $this->assertLevels($r->parentRole->level, $level, $r->relation_type);
        }
    }

    private function assertLevels(int $parent, int $child, string $type): void
    {
        if (($type === RoleRelation::TYPE_TOP_DOWN && $parent >= $child) || ($type === RoleRelation::TYPE_SAME_LEVEL && $parent !== $child)) {
            throw ValidationException::withMessages(['level' => 'سطح جدید با ارتباط‌های موجود این نقش سازگار نیست.']);
        }
    }
}
