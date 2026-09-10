<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    public function paginate(array $filters, User $actor): LengthAwarePaginator
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
            ->where('is_super_admin', false)
            ->when(! $actor->is_super_admin, fn ($query) => $query->where('is_admin', false))
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
        if (User::query()->where('is_super_admin', true)->exists()) {
            throw new HttpException(409, 'مدیر اولیه قبلاً ساخته شده است.');
        }

        return $this->ensureSuperAdmin($data);
    }

    public function ensureSuperAdmin(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $mobile = MobileNumber::normalize($data['mobile']);
            $mobileVariants = MobileNumber::variants($mobile);
            $otherSuperAdmin = User::withTrashed()
                ->where('is_super_admin', true)
                ->whereNotIn('mobile', $mobileVariants)
                ->lockForUpdate()
                ->first();

            if ($otherSuperAdmin) {
                throw new HttpException(409, 'یک سوپرادمین دیگر در سامانه وجود دارد.');
            }

            $user = User::withTrashed()
                ->whereIn('mobile', $mobileVariants)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'mobile' => $mobile,
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'is_admin' => true,
                'is_super_admin' => true,
                'must_change_password' => false,
                'deleted_at' => null,
            ];

            if ($user) {
                $user->forceFill($attributes)->save();
                $user->tokens()->delete();

                return $user->fresh(['specialDates', 'role']);
            }

            return $this->createUser([
                ...$data,
                'mobile' => $mobile,
                'is_active' => true,
                'is_admin' => true,
                'is_super_admin' => true,
            ], null);
        });
    }

    public function create(array $data, User $actor): User
    {
        if ($this->toBool($data['is_admin'] ?? false) && ! $actor->is_super_admin) {
            throw new HttpException(403, 'فقط سوپرادمین می‌تواند مدیر جدید ایجاد کند.');
        }

        $data['is_admin'] = $this->toBool($data['is_admin'] ?? false);
        $data['is_super_admin'] = false;

        return $this->createUser($data, $actor);
    }

    private function createUser(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $specialDates = $data['special_dates'] ?? [];
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'role_ids', 'role_id', 'org_code', 'is_admin', 'is_super_admin']);

            $userData['org_code'] = $this->generateOrgCode();
            $userData['created_by'] = $actor?->id;
            $userData['updated_by'] = $actor?->id;
            $userData['is_admin'] = $data['is_admin'] ?? false;
            $userData['is_super_admin'] = $data['is_super_admin'] ?? false;

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
        $this->assertCanManage($user, $actor);

        if (($this->isFalse($data['is_active'] ?? true) || $this->isFalse($data['is_admin'] ?? true)) && $user->id === $actor->id) {
            throw new HttpException(422, 'مدیر نمی‌تواند حساب یا دسترسی مدیریتی خودش را غیرفعال کند.');
        }

        $hasAdminChange = array_key_exists('is_admin', $data)
            && $this->toBool($data['is_admin']) !== $user->is_admin;

        if ($hasAdminChange && ! $actor->is_super_admin) {
            throw new HttpException(403, 'فقط سوپرادمین می‌تواند دسترسی مدیریتی را تغییر دهد.');
        }

        return DB::transaction(function () use ($user, $data, $actor, $hasAdminChange): User {
            $hasSpecialDates = array_key_exists('special_dates', $data);
            $specialDates = $data['special_dates'] ?? [];
            $hasRoleAssignment = array_key_exists('role_id', $data);
            $roleId = $data['role_id'] ?? null;
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'role_ids', 'role_id', 'org_code', 'is_admin', 'is_super_admin']);

            if ($hasAdminChange) {
                $userData['is_admin'] = $this->toBool($data['is_admin']);
            }

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

            if ($hasRoleAssignment) {
                $this->syncRole($user, $roleId);
            }

            if (array_key_exists('is_active', $userData) && $this->isFalse($userData['is_active'])) {
                $user->tokens()->delete();
            }

            if ($hasAdminChange) {
                $user->tokens()->delete();
            }

            if ($hasSpecialDates) {
                $this->syncSpecialDates($user, $specialDates);
            }

            return $user->fresh(['specialDates', 'role']);
        });
    }

    public function activate(User $user, User $actor): User
    {
        $this->assertCanManage($user, $actor);

        $user->forceFill([
            'is_active' => true,
            'updated_by' => $actor->id,
        ])->save();

        return $user->fresh('specialDates');
    }

    public function deactivate(User $user, User $actor): User
    {
        $this->assertCanManage($user, $actor);

        if ($user->is_super_admin) {
            throw new HttpException(422, 'حساب سوپرادمین قابل غیرفعال‌سازی نیست.');
        }

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
        $this->assertCanManage($user, $actor);

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
        $this->assertCanManage($user, $actor);

        if ($user->is_super_admin) {
            throw new HttpException(422, 'حساب سوپرادمین قابل حذف نیست.');
        }

        if ($user->id === $actor->id) {
            throw new HttpException(422, 'مدیر نمی‌تواند حساب خودش را حذف کند.');
        }

        if ($user->role()->exists()) {
            throw new HttpException(422, 'این کاربر در ساختار سازمانی قرار دارد؛ ابتدا نقش او را از ساختار سازمانی حذف کنید.');
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

    private function syncRole(User $user, ?int $roleId): void
    {
        $currentRole = $user->role;
        $targetRole = $roleId ? Role::query()->lockForUpdate()->findOrFail($roleId) : null;

        if ($targetRole && $targetRole->user_id && $targetRole->user_id !== $user->id) {
            throw new HttpException(422, 'این نقش قبلاً به کاربر دیگری اختصاص داده شده است.');
        }

        if ($currentRole && (! $targetRole || $currentRole->id !== $targetRole->id)) {
            $currentRole->update(['user_id' => null]);
        }

        if ($targetRole && $targetRole->user_id !== $user->id) {
            $targetRole->update(['user_id' => $user->id]);
        }
    }

    private function isFalse(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;
    }

    public function assertCanManage(User $user, User $actor): void
    {
        if ($user->is_super_admin && $user->id !== $actor->id) {
            throw new HttpException(403, 'حساب سوپرادمین فقط توسط خودش قابل مشاهده و ویرایش است.');
        }

        if ($user->is_admin && ! $actor->is_super_admin && $user->id !== $actor->id) {
            throw new HttpException(403, 'فقط سوپرادمین می‌تواند حساب مدیران را مدیریت کند.');
        }
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
