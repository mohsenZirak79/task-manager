<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $hasActiveFilter = array_key_exists('is_active', $filters)
            && $filters['is_active'] !== null
            && $filters['is_active'] !== '';
        $isActive = $hasActiveFilter
            ? filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return User::query()
            ->with('specialDates')
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('org_code', 'like', "%{$search}%");
                });
            })
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->latest()
            ->paginate($perPage);
    }

    public function register(array $data): User
    {
        $data['is_active'] = true;
        $data['is_admin'] = false;

        return $this->createUser($data, null);
    }

    public function createInitialAdmin(array $data): User
    {
        if (User::query()->where('is_admin', true)->exists()) {
            throw new HttpException(409, 'مدیر اولیه قبلاً ساخته شده است.');
        }

        $data['is_active'] = true;
        $data['is_admin'] = true;

        return $this->createUser($data, null);
    }

    public function create(array $data, User $actor): User
    {
        return $this->createUser($data, $actor);
    }

    private function createUser(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $specialDates = $data['special_dates'] ?? [];
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'role_ids', 'org_code']);

            $userData['org_code'] = $this->generateOrgCode();
            $userData['created_by'] = $actor?->id;
            $userData['updated_by'] = $actor?->id;

            if (! empty($data['password'])) {
                $userData['password'] = Hash::make($data['password']);
                $userData['must_change_password'] = false;
            } else {
                $userData['password'] = null;
                $userData['must_change_password'] = true;
            }

            $user = User::query()->create($userData);
            $this->syncSpecialDates($user, $specialDates);

            return $user->load('specialDates');
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        if (($this->isFalse($data['is_active'] ?? true) || $this->isFalse($data['is_admin'] ?? true)) && $user->id === $actor->id) {
            throw new HttpException(422, 'مدیر نمی‌تواند حساب یا دسترسی مدیریتی خودش را غیرفعال کند.');
        }

        return DB::transaction(function () use ($user, $data, $actor): User {
            $hasSpecialDates = array_key_exists('special_dates', $data);
            $specialDates = $data['special_dates'] ?? [];
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'role_ids', 'org_code']);

            if (array_key_exists('password', $userData)) {
                if ($userData['password']) {
                    $userData['password'] = Hash::make($userData['password']);
                    $userData['must_change_password'] = false;
                } else {
                    unset($userData['password']);
                }
            }

            $userData['updated_by'] = $actor->id;
            $user->update($userData);

            if (array_key_exists('is_active', $userData) && $this->isFalse($userData['is_active'])) {
                $user->tokens()->delete();
            }

            if ($hasSpecialDates) {
                $this->syncSpecialDates($user, $specialDates);
            }

            return $user->fresh('specialDates');
        });
    }

    public function activate(User $user, User $actor): User
    {
        $user->forceFill([
            'is_active' => true,
            'updated_by' => $actor->id,
        ])->save();

        return $user->fresh('specialDates');
    }

    public function deactivate(User $user, User $actor): User
    {
        if ($user->id === $actor->id) {
            throw new HttpException(422, 'مدیر نمی‌تواند حساب خودش را غیرفعال کند.');
        }

        $user->forceFill([
            'is_active' => false,
            'updated_by' => $actor->id,
        ])->save();
        $user->tokens()->delete();

        return $user->fresh('specialDates');
    }

    public function resetPassword(User $user, string $password, User $actor): User
    {
        return DB::transaction(function () use ($user, $password, $actor): User {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'updated_by' => $actor->id,
            ])->save();
            $user->tokens()->delete();

            return $user->fresh('specialDates');
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw new HttpException(422, 'مدیر نمی‌تواند حساب خودش را حذف کند.');
        }

        $user->tokens()->delete();
        $user->delete();
    }

    private function generateOrgCode(): string
    {
        $lastCode = User::withTrashed()
            ->lockForUpdate()
            ->orderByRaw('CAST(org_code AS UNSIGNED) DESC')
            ->value('org_code');

        return (string) max(100001, ((int) $lastCode) + 1);
    }

    private function syncSpecialDates(User $user, array $specialDates): void
    {
        $user->specialDates()->delete();

        foreach ($specialDates as $specialDate) {
            $user->specialDates()->create($specialDate);
        }
    }

    private function isFalse(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;
    }
}
