<?php

namespace Tests\Feature;

use App\Models\AuthOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled_by_default(): void
    {
        config()->set('auth_flow.registration_enabled', false);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertForbidden();
    }

    public function test_test_phase_registration_and_password_login_work(): void
    {
        config()->set('auth_flow.registration_enabled', true);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.user.is_admin', false);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '09121111111',
            'password' => 'SecurePass!123',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $user = User::query()->where('mobile', '09121111111')->firstOrFail();
        $this->assertFalse($user->is_admin);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_password_login_is_rate_limited_per_identifier(): void
    {
        User::factory()->create([
            'mobile' => '09126666666',
            'password' => Hash::make('CorrectPassword!123'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => '09126666666',
                'password' => 'WrongPassword!123',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '09126666666',
            'password' => 'WrongPassword!123',
        ])->assertTooManyRequests();
    }

    public function test_login_otp_forgot_password_and_invitation_flows_work_in_tests(): void
    {
        config()->set('auth_flow.otp.driver', 'log');
        config()->set('auth_flow.otp.fixed_code', '123456');

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'mobile' => '09122222222',
            'email' => 'otp@example.test',
            'password' => Hash::make('OldPassword!123'),
        ]);

        $this->postJson('/api/v1/auth/otp/send', [
            'identifier' => $user->mobile,
            'purpose' => AuthOtp::PURPOSE_LOGIN,
        ])->assertOk()->assertJsonMissingPath('data.otp_debug_code');

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->mobile,
            'purpose' => AuthOtp::PURPOSE_LOGIN,
            'code' => '123456',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => $user->mobile,
        ])->assertOk();

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'unknown@example.test',
        ])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->mobile,
            'purpose' => AuthOtp::PURPOSE_FORGOT_PASSWORD,
            'code' => '123456',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->mobile,
            'purpose' => AuthOtp::PURPOSE_FORGOT_PASSWORD,
            'code' => '123456',
            'password' => 'ResetPassword!123',
            'password_confirmation' => 'ResetPassword!123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->mobile,
            'password' => 'ResetPassword!123',
        ])->assertOk();

        $invited = User::factory()->create([
            'mobile' => '09123333333',
            'password' => null,
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/users/{$invited->id}/send-invite")->assertOk();

        $this->postJson('/api/v1/auth/password/set', [
            'identifier' => $invited->mobile,
            'purpose' => AuthOtp::PURPOSE_SET_PASSWORD,
            'code' => '123456',
            'password' => 'FirstPassword!123',
            'password_confirmation' => 'FirstPassword!123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $invited->mobile,
            'password' => 'FirstPassword!123',
        ])->assertOk();
    }

    private function registrationPayload(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'User',
            'mobile' => '09121111111',
            'email' => 'registered@example.test',
            'password' => 'SecurePass!123',
            'password_confirmation' => 'SecurePass!123',
        ];
    }
}
