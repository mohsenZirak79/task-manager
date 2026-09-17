<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_manage_users(): void
    {
        $regularUser = User::factory()->create();
        Sanctum::actingAs($regularUser);

        $this->getJson('/api/v1/users')->assertForbidden();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Managed',
            'last_name' => 'User',
            'mobile' => '09124444444',
            'email' => 'managed@example.test',
            'password' => 'ManagedPass!123',
            'password_confirmation' => 'ManagedPass!123',
            'is_active' => true,
        ])->assertCreated();

        $userId = $response->json('data.user.id');
        $this->getJson("/api/v1/users/{$userId}")->assertOk();
        $this->patchJson("/api/v1/users/{$userId}", ['title' => 'Updated'])->assertOk();
    }

    public function test_admin_cannot_remove_own_access_or_delete_self(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/users/{$admin->id}", ['is_admin' => false])
            ->assertUnprocessable();
        $this->postJson("/api/v1/users/{$admin->id}/deactivate")
            ->assertUnprocessable();
        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertUnprocessable();
    }

    public function test_user_list_only_filters_activity_when_the_filter_has_a_value(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);

        $this->getJson('/api/v1/users?is_active=')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);

        $this->getJson('/api/v1/users?is_active=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson('/api/v1/users?is_active=0')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_deactivating_a_user_revokes_existing_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminToken = $admin->createToken('admin-token')->plainTextToken;
        $plainToken = $user->createToken('test-token')->plainTextToken;

        $this->withToken($adminToken)
            ->postJson("/api/v1/users/{$user->id}/deactivate")
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($plainToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }
}
