<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertTrue($admin->is_active);

        $this->artisan('app:create-admin', $options)->assertFailed();
        $this->assertSame(1, User::query()->count());
    }
}
