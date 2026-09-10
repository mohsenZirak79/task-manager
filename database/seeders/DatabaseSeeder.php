<?php

namespace Database\Seeders;

use App\Services\UserService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $config = config('auth_flow.bootstrap_admin');

        if (! $config['mobile'] || ! $config['password']) {
            $this->command?->warn('Bootstrap admin was skipped: BOOTSTRAP_ADMIN_MOBILE and BOOTSTRAP_ADMIN_PASSWORD are required.');

            return;
        }

        app(UserService::class)->ensureSuperAdmin($config);
    }
}
