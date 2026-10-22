<?php

namespace Tests\Feature;

use App\Models\AuthOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangeOwnPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/password/change';

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'current_password' => 'OldPassword!123',
            'password' => 'NewPassword!456',
            'password_confirmation' => 'NewPassword!456',
        ], $overrides);
    }

    public function test_any_active_user_can_change_only_their_own_password_and_keep_current_token(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('OldPassword!123'), 'must_change_password' => true]);
        $other = User::factory()->create(['password' => Hash::make('OtherPassword!123')]);
        $current = $owner->createToken('current');
        $stale = $owner->createToken('other-device');
        $otherToken = $other->createToken('unrelated');
        $otp = AuthOtp::query()->create([
            'user_id' => $owner->id, 'identifier' => $owner->mobile,
            'purpose' => AuthOtp::PURPOSE_FORGOT_PASSWORD, 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->withToken($current->plainTextToken)->postJson(self::ENDPOINT, $this->payload([
            'user_id' => $other->id, 'identifier' => $other->mobile,
        ]))->assertOk()->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.token');

        $this->assertTrue(Hash::check('NewPassword!456', $owner->fresh()->password));
        $this->assertFalse(Hash::check('OldPassword!123', $owner->fresh()->password));
        $this->assertFalse($owner->fresh()->must_change_password);
        $this->assertTrue(Hash::check('OtherPassword!123', $other->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $stale->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->assertNotNull($otp->fresh()->used_at);
        // Forget the cached guard so this really authenticates the retained token again.
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($stale->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_current_password_changes_nothing(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('OldPassword!123')]);
        $token = $owner->createToken('current');
        $this->withToken($token->plainTextToken)->postJson(self::ENDPOINT, $this->payload([
            'current_password' => 'WrongPassword!123',
        ]))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('OldPassword!123', $owner->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_new_password_requires_confirmation_strength_and_bcrypt_byte_limit(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('OldPassword!123')]);
        $token = $owner->createToken('current');
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ([
            ['password_confirmation' => 'Mismatch!123'],
            ['password' => 'OldPassword!123', 'password_confirmation' => 'OldPassword!123'],
            ['password' => 'Short1!', 'password_confirmation' => 'Short1!'],
            ['password' => 'NoNumbers!', 'password_confirmation' => 'NoNumbers!'],
            ['password' => 'NoSymbols123', 'password_confirmation' => 'NoSymbols123'],
            ['password' => '12345678!', 'password_confirmation' => '12345678!'],
            ['password' => str_repeat('ن', 36).'1!', 'password_confirmation' => str_repeat('ن', 36).'1!'],
            ['password' => ['invalid'], 'password_confirmation' => ['invalid']],
        ] as $overrides) {
            $this->withToken($token->plainTextToken)->postJson(self::ENDPOINT, $this->payload($overrides))
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->withToken($token->plainTextToken)->postJson(self::ENDPOINT, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
        $this->assertTrue(Hash::check('OldPassword!123', $owner->fresh()->password));
    }

    public function test_endpoint_requires_authentication_and_active_account(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        $inactive = User::factory()->create(['is_active' => false]);
        $this->withToken($inactive->createToken('inactive')->plainTextToken)
            ->postJson(self::ENDPOINT, $this->payload())->assertForbidden();
    }

    public function test_attempts_are_rate_limited_and_do_not_lock_out_another_user(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('OldPassword!123')]);
        $token = $owner->createToken('current');
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token->plainTextToken)->postJson(self::ENDPOINT,
                $this->payload(['current_password' => 'wrong']))->assertUnprocessable();
        }
        $this->withToken($token->plainTextToken)->postJson(self::ENDPOINT, $this->payload())->assertTooManyRequests();
        $this->app['auth']->forgetGuards();
        $other = User::factory()->create(['password' => Hash::make('OldPassword!123')]);
        $this->withToken($other->createToken('current')->plainTextToken)
            ->postJson(self::ENDPOINT, $this->payload())->assertOk();
    }

    public function test_passwords_with_spaces_are_not_trimmed(): void
    {
        $owner = User::factory()->create(['password' => Hash::make(' OldPassword!123 ')]);
        $this->withToken($owner->createToken('current')->plainTextToken)->postJson(self::ENDPOINT,
            $this->payload(['current_password' => ' OldPassword!123 ', 'password' => ' NewPassword!456 ', 'password_confirmation' => ' NewPassword!456 ']))
            ->assertOk();
        $this->assertTrue(Hash::check(' NewPassword!456 ', $owner->fresh()->password));
    }
}
