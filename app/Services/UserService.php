<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $isActive = filter_var($filters['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

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
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $specialDates = $data['special_dates'] ?? [];
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'role_ids', 'org_code']);

            $userData['org_code'] = $this->generateOrgCode();
            $userData['created_by'] = $actor->id;
            $userData['updated_by'] = $actor->id;

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
        $user->forceFill([
            'is_active' => false,
            'updated_by' => $actor->id,
        ])->save();

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

            return $user->fresh('specialDates');
        });
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
}
