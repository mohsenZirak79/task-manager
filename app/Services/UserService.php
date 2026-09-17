<?php

namespace App\Services;

use App\Models\AccessRole;
use App\Models\OrgPosition;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\MobileNumber;
use App\Support\Permissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    private const RELATIONS = ['specialDates', 'accessRoles.permissions', 'orgPositions'];

    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $hasActiveFilter = array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '';
        $isActive = $hasActiveFilter ? filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return User::query()
            ->with(self::RELATIONS)
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereDoesntHave(
                'accessRoles',
                fn ($query) => $query->where('slug', AccessRoles::SUPER_ADMIN)->where('is_system', true),
            ))
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

        return $this->createUser($data, null);
    }

    public function createInitialAdmin(array $data): User
    {
        if ($this->superAdminQuery()->exists()) {
            throw new HttpException(409, 'مدیر اولیه قبلاً ساخته شده است.');
        }

        return $this->ensureSuperAdmin($data);
    }

    public function ensureSuperAdmin(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $role = AccessRole::query()
                ->where('slug', AccessRoles::SUPER_ADMIN)
                ->where('is_system', true)
                ->lockForUpdate()
                ->first();

            if (! $role) {
                throw new HttpException(409, 'کنترل دسترسی آماده نیست؛ ابتدا php artisan access:sync را اجرا کنید.');
            }

            $mobile = MobileNumber::normalize($data['mobile']);
            $variants = MobileNumber::variants($mobile);
            $other = $this->superAdminQuery()->whereNotIn('mobile', $variants)->lockForUpdate()->first();
            if ($other) {
                throw new HttpException(409, 'یک سوپرادمین دیگر در سامانه وجود دارد.');
            }

            $user = User::withTrashed()->whereIn('mobile', $variants)->lockForUpdate()->first();
            if (($data['email'] ?? null) && User::withTrashed()
                ->where('email', $data['email'])
                ->when($user, fn ($query) => $query->whereKeyNot($user->id))
                ->exists()) {
                throw new HttpException(422, 'ایمیل قبلاً ثبت شده است.');
            }
            $attributes = [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'mobile' => $mobile,
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'must_change_password' => false,
                'deleted_at' => null,
            ];

            if ($user) {
                $user->forceFill($attributes)->save();
            } else {
                $user = User::query()->create([...$attributes, 'org_code' => $this->generateOrgCode()]);
            }

            $user->accessRoles()->syncWithoutDetaching([$role->id]);
            $user->tokens()->delete();

            return $user->fresh(self::RELATIONS);
        });
    }

    public function create(array $data, User $actor): User
    {
        if (array_key_exists('access_role_ids', $data)) {
            $this->assertCanManageAccess($actor, $data['access_role_ids']);
        }

        return $this->createUser($data, $actor);
    }

    private function createUser(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $specialDates = $data['special_dates'] ?? [];
            $accessRoleIds = $data['access_role_ids'] ?? null;
            $orgPositionIds = $data['org_position_ids'] ?? [];
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'access_role_ids', 'org_position_ids', 'org_code']);
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
            $this->syncAccessRoles($user, $accessRoleIds ?? [$this->defaultAccessRoleId()]);
            $this->syncOrgPositions($user, $orgPositionIds);

            return $user->load(self::RELATIONS);
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        $this->assertCanManage($user, $actor);

        if (array_key_exists('access_role_ids', $data)) {
            $this->assertCanManageAccess($actor, $data['access_role_ids'], $user);
        }

        if (array_key_exists('is_active', $data) && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) && $user->id === $actor->id) {
            throw new HttpException(422, 'کاربر نمی‌تواند حساب خودش را غیرفعال کند.');
        }

        return DB::transaction(function () use ($user, $data, $actor): User {
            $userData = Arr::except($data, ['special_dates', 'password_confirmation', 'access_role_ids', 'org_position_ids', 'org_code']);
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

            if (array_key_exists('special_dates', $data)) {
                $this->syncSpecialDates($user, $data['special_dates'] ?? []);
            }
            if (array_key_exists('access_role_ids', $data)) {
                $this->syncAccessRoles($user, $data['access_role_ids']);
                $user->tokens()->delete();
            }
            if (array_key_exists('org_position_ids', $data)) {
                $this->syncOrgPositions($user, $data['org_position_ids']);
            }
            if (array_key_exists('is_active', $userData) && ! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user->fresh(self::RELATIONS);
        });
    }

    public function activate(User $user, User $actor): User
    {
        $this->assertCanManage($user, $actor);
        $user->forceFill(['is_active' => true, 'updated_by' => $actor->id])->save();

        return $user->fresh(self::RELATIONS);
    }

    public function deactivate(User $user, User $actor): User
    {
        $this->assertCanManage($user, $actor);
        if ($user->isSuperAdmin()) {
            throw new HttpException(422, 'حساب سوپرادمین قابل غیرفعال‌سازی نیست.');
        }
        if ($user->id === $actor->id) {
            throw new HttpException(422, 'کاربر نمی‌تواند حساب خودش را غیرفعال کند.');
        }
        $user->forceFill(['is_active' => false, 'updated_by' => $actor->id])->save();
        $user->tokens()->delete();

        return $user->fresh(self::RELATIONS);
    }

    public function resetPassword(User $user, string $password, User $actor): User
    {
        $this->assertCanManage($user, $actor);

        return DB::transaction(function () use ($user, $password, $actor): User {
            $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false, 'updated_by' => $actor->id])->save();
            $user->tokens()->delete();

            return $user->fresh(self::RELATIONS);
        });
    }

    public function delete(User $user, User $actor): void
    {
        $this->assertCanManage($user, $actor);
        if ($user->isSuperAdmin()) {
            throw new HttpException(422, 'حساب سوپرادمین قابل حذف نیست.');
        }
        if ($user->id === $actor->id) {
            throw new HttpException(422, 'کاربر نمی‌تواند حساب خودش را حذف کند.');
        }
        if ($user->orgPositions()->exists()) {
            throw new HttpException(422, 'این کاربر در ساختار سازمانی قرار دارد؛ ابتدا جایگاه او را آزاد کنید.');
        }
        $user->tokens()->delete();
        $user->delete();
    }

    public function assertCanManage(User $user, User $actor): void
    {
        if ($user->isSuperAdmin() && $user->id !== $actor->id) {
            throw new HttpException(403, 'حساب سوپرادمین فقط توسط خودش قابل مدیریت است.');
        }
    }

    private function assertCanManageAccess(User $actor, array $roleIds, ?User $target = null): void
    {
        if (! $actor->hasPermission(Permissions::USERS_MANAGE_ACCESS)) {
            throw new HttpException(403, 'مجوز تغییر نقش‌های دسترسی را ندارید.');
        }

        $assignsSuperAdmin = AccessRole::query()->whereKey($roleIds)
            ->where('slug', AccessRoles::SUPER_ADMIN)->where('is_system', true)->exists();
        if ($assignsSuperAdmin && ! $actor->isSuperAdmin()) {
            throw new HttpException(403, 'فقط سوپرادمین می‌تواند نقش سیستمی سوپرادمین را تخصیص دهد.');
        }
        if ($assignsSuperAdmin && ($target === null || ! $target->isSuperAdmin()) && $this->superAdminQuery()->exists()) {
            throw new HttpException(409, 'یک سوپرادمین دیگر در سامانه وجود دارد.');
        }
        if ($target?->isSuperAdmin() && ! $assignsSuperAdmin) {
            throw new HttpException(422, 'نقش سوپرادمین اصلی قابل حذف نیست.');
        }
    }

    private function syncAccessRoles(User $user, array $roleIds): void
    {
        $user->accessRoles()->sync(array_values(array_unique(array_map('intval', $roleIds))));
    }

    private function syncOrgPositions(User $user, array $positionIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $positionIds)));
        OrgPosition::query()->whereKey($ids)->lockForUpdate()->get(['id']);
        if ($ids && DB::table('org_position_user')->whereIn('org_position_id', $ids)->where('user_id', '!=', $user->id)->exists()) {
            throw new HttpException(422, 'حداقل یکی از جایگاه‌ها به کاربر دیگری اختصاص داده شده است.');
        }
        $user->orgPositions()->sync($ids);
    }

    private function syncSpecialDates(User $user, array $specialDates): void
    {
        $user->specialDates()->delete();
        foreach ($specialDates as $specialDate) {
            $user->specialDates()->create($specialDate);
        }
    }

    private function defaultAccessRoleId(): int
    {
        $id = AccessRole::query()->where('slug', AccessRoles::USER)->value('id');
        if (! $id) {
            throw new HttpException(409, 'کنترل دسترسی آماده نیست؛ ابتدا php artisan access:sync را اجرا کنید.');
        }

        return (int) $id;
    }

    private function superAdminQuery()
    {
        return User::withTrashed()->whereHas('accessRoles', fn ($query) => $query
            ->where('slug', AccessRoles::SUPER_ADMIN)->where('is_system', true));
    }

    private function generateOrgCode(): string
    {
        $lastCode = User::withTrashed()->lockForUpdate()->orderByDesc('org_code')->value('org_code');

        return (string) max(100001, ((int) $lastCode) + 1);
    }
}
