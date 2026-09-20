<?php

namespace Database\Factories;

use App\Models\AccessRole;
use App\Models\User;
use App\Support\AccessRoles;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_code' => fake()->unique()->numerify('######'),
            'username' => fake()->unique()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'mobile' => fake()->unique()->numerify('09#########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! AccessRole::query()
                ->where('slug', AccessRoles::USER)
                ->whereHas('permissions', fn ($query) => $query->where('name', Permissions::TASKS_CREATE))
                ->exists()) {
                Artisan::call('access:sync');
            }
            $role = AccessRole::query()->where('slug', AccessRoles::USER)->first();
            if ($role) {
                $user->accessRoles()->syncWithoutDetaching([$role->id]);
            }
        });
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $role = AccessRole::query()->where('slug', AccessRoles::SUPER_ADMIN)->first();
            if ($role) {
                $user->accessRoles()->syncWithoutDetaching([$role->id]);
            }
        });
    }
}
