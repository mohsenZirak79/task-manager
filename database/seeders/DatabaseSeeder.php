<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(['mobile' => '09120000000'], ['first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin@example.com', 'org_code' => '100001', 'password' => Hash::make('password'), 'is_active' => true, 'must_change_password' => false]);
        $this->call(RoleSeeder::class);
    }
}
