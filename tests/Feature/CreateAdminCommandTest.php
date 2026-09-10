<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_only_the_first_admin(): void
    {
        $options = [
            '--first-name' => 'Initial',
            '--last-name' => 'Admin',
            '--mobile' => '09125555555',
            '--email' => 'initial-admin@example.test',
            '--password' => 'InitialAdmin!123',
        ];

        $this->artisan('app:create-admin', $options)->assertSuccessful();

        $admin = User::query()->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_super_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check($options['--password'], $admin->password));

        $this->artisan('app:create-admin', $options)->assertFailed();
        $this->assertSame(1, User::query()->count());
    }

    public function test_database_seeder_restores_and_configures_the_single_super_admin(): void
    {
        $existing = User::factory()->create([
            'first_name' => 'Old',
            'last_name' => 'Account',
            'mobile' => '+989021330912',
        ]);
        $existing->delete();

        config()->set('auth_flow.bootstrap_admin', [
            'first_name' => 'محسن',
            'last_name' => 'زیرک',
            'mobile' => '۰۹۰۲۱۳۳۰۹۱۲',
            'email' => null,
            'password' => 'SeededPassword!123',
        ]);

        $this->seed();
        $this->seed();

        $superAdmin = User::query()->where('mobile', '09021330912')->firstOrFail();

        $this->assertSame($existing->id, $superAdmin->id);
        $this->assertTrue($superAdmin->is_admin);
        $this->assertTrue($superAdmin->is_super_admin);
        $this->assertTrue($superAdmin->is_active);
        $this->assertTrue(Hash::check('SeededPassword!123', $superAdmin->password));
        $this->assertSame(1, User::query()->where('is_super_admin', true)->count());
    }
}
