<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleRelation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleRelationService
{
    public function paginate(array $f): LengthAwarePaginator
    {
        return RoleRelation::query()->with(['parentRole', 'childRole'])->when($f['relation_type'] ?? null, fn ($q, $v) => $q->where('relation_type', $v))->when($f['parent_role_id'] ?? null, fn ($q, $v) => $q->where('parent_role_id', $v))->when($f['child_role_id'] ?? null, fn ($q, $v) => $q->where('child_role_id', $v))->latest()->paginate(min(max((int) ($f['per_page'] ?? 15), 1), 100));
    }

    public function create(array $data): RoleRelation
    {
        return DB::transaction(function () use ($data) {
            $this->validate($data);

            return RoleRelation::query()->create($data)->load(['parentRole', 'childRole']);
        });
    }

    public function update(RoleRelation $relation, array $data): RoleRelation
    {
        return DB::transaction(function () use ($relation, $data) {
            $merged = array_merge($relation->only(['parent_role_id', 'child_role_id', 'relation_type']), $data);
            $this->validate($merged, $relation);
            $relation->update($data);

            return $relation->fresh(['parentRole', 'childRole']);
        });
    }

    public function delete(RoleRelation $relation): void
    {
        DB::transaction(fn () => $relation->delete());
    }

    private function validate(array $d, ?RoleRelation $ignore = null): void
    {
        if ((int) $d['parent_role_id'] === (int) $d['child_role_id']) {
            throw ValidationException::withMessages(['child_role_id' => 'نقش والد و فرزند نباید یکسان باشند.']);
        } $parent = Role::query()->findOrFail($d['parent_role_id']);
        $child = Role::query()->findOrFail($d['child_role_id']);
        if ($d['relation_type'] === RoleRelation::TYPE_TOP_DOWN && $parent->level >= $child->level) {
            throw ValidationException::withMessages(['parent_role_id' => 'در ارتباط top_down عدد level والد باید کمتر از فرزند باشد.']);
        } if ($d['relation_type'] === RoleRelation::TYPE_SAME_LEVEL && $parent->level !== $child->level) {
            throw ValidationException::withMessages(['parent_role_id' => 'در ارتباط same_level سطح دو نقش باید برابر باشد.']);
        } $q = RoleRelation::query()->where($d);
        if ($ignore) {
            $q->whereKeyNot($ignore->id);
        }if ($q->exists()) {
            throw ValidationException::withMessages(['relation_type' => 'این ارتباط قبلاً ایجاد شده است.']);
        }
    }
}
