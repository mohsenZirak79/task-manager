<?php

namespace App\Services;

use App\Models\UserRelationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserRelationExceptionService
{
    public function paginate(array $f): LengthAwarePaginator
    {
        return UserRelationException::query()->with(['fromUser', 'toUser'])->when($f['from_user_id'] ?? null, fn ($q, $v) => $q->where('from_user_id', $v))->when($f['to_user_id'] ?? null, fn ($q, $v) => $q->where('to_user_id', $v))->when($f['permission_type'] ?? null, fn ($q, $v) => $q->where('permission_type', $v))->latest()->paginate(min(max((int) ($f['per_page'] ?? 15), 1), 100));
    }

    public function create(array $data): UserRelationException
    {
        return DB::transaction(function () use ($data) {
            $this->validate($data);

            return UserRelationException::query()->create($data)->load(['fromUser', 'toUser']);
        });
    }

    public function update(UserRelationException $exception, array $data): UserRelationException
    {
        return DB::transaction(function () use ($exception, $data) {
            $merged = array_merge($exception->only(['from_user_id', 'to_user_id', 'permission_type', 'description']), $data);
            $this->validate($merged, $exception);
            $exception->update($data);

            return $exception->fresh(['fromUser', 'toUser']);
        });
    }

    public function delete(UserRelationException $exception): void
    {
        DB::transaction(fn () => $exception->delete());
    }

    private function validate(array $d, ?UserRelationException $ignore = null): void
    {
        if ((int) $d['from_user_id'] === (int) $d['to_user_id']) {
            throw ValidationException::withMessages(['to_user_id' => 'کاربر مبدأ و مقصد نباید یکسان باشند.']);
        }$q = UserRelationException::query()->where('from_user_id', $d['from_user_id'])->where('to_user_id', $d['to_user_id'])->where('permission_type', $d['permission_type']);
        if ($ignore) {
            $q->whereKeyNot($ignore->id);
        }if ($q->exists()) {
            throw ValidationException::withMessages(['permission_type' => 'این استثنا قبلاً ایجاد شده است.']);
        }
    }
}
