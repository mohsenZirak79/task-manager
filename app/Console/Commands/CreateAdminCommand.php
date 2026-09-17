<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserService;
use App\Support\AccessRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {--first-name= : Admin first name}
        {--last-name= : Admin last name}
        {--mobile= : Admin mobile number}
        {--email= : Admin email address}
        {--password= : Admin password}';

    protected $description = 'Create the first administrator account securely';

    public function handle(UserService $userService): int
    {
        Artisan::call('access:sync');

        if (User::query()->whereHas('accessRoles', fn ($query) => $query->where('slug', AccessRoles::SUPER_ADMIN)->where('is_system', true))->exists()) {
            $this->error('A super administrator already exists.');

            return self::FAILURE;
        }

        $data = [
            'first_name' => $this->value('first-name', 'first_name'),
            'last_name' => $this->value('last-name', 'last_name'),
            'mobile' => $this->value('mobile', 'mobile'),
            'email' => $this->value('email', 'email', false),
            'password' => $this->password(),
        ];

        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $userService->createInitialAdmin($validator->validated());
        $this->info("Super administrator created with ID {$user->id}.");

        return self::SUCCESS;
    }

    private function value(string $option, string $configKey, bool $required = true): ?string
    {
        $value = $this->option($option) ?: config("auth_flow.bootstrap_admin.{$configKey}");

        if (! $value && $required && $this->input->isInteractive()) {
            $value = $this->ask(str_replace('-', ' ', ucfirst($option)));
        }

        return $value ?: null;
    }

    private function password(): ?string
    {
        $password = $this->option('password') ?: config('auth_flow.bootstrap_admin.password');

        if (! $password && $this->input->isInteractive()) {
            $password = $this->secret('Password');
        }

        return $password ?: null;
    }
}
