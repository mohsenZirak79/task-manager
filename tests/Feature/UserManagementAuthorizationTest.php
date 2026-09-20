<?php

namespace Tests\Feature;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
            'username' => 'managed.user',
            'first_name' => 'Managed',
            'last_name' => 'User',
            'mobile' => '09124444444',
            'email' => 'managed@example.test',
            'password' => 'ManagedPass!123',
            'password_confirmation' => 'ManagedPass!123',
            'is_active' => true,
        ])->assertCreated();

        $userId = $response->json('data.user.id');
        $response->assertJsonPath('data.user.username', 'managed.user');
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

    public function test_username_password_and_status_contract_is_enforced(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->create(['username' => 'existing.user']);
        Sanctum::actingAs($admin);

        $payload = [
            'first_name' => 'Contract',
            'last_name' => 'User',
            'mobile' => '09125555555',
            'password' => 'ContractPass!123',
            'password_confirmation' => 'ContractPass!123',
        ];

        $this->postJson('/api/v1/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->postJson('/api/v1/users', [...$payload, 'username' => 'existing.user'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->postJson('/api/v1/users', [
            ...$payload,
            'username' => 'without.password',
            'password' => null,
            'password_confirmation' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['password', 'password_confirmation']);

        $this->patchJson("/api/v1/users/{$existing->id}", ['username' => 'Edited.User'])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'edited.user');

        $this->patchJson("/api/v1/users/{$existing->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_user_list_supports_username_search_page_activity_and_assigned_task_count(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'list.admin']);
        $user = User::factory()->create([
            'username' => 'searchable.user',
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
        ]);
        User::factory()->count(2)->create();

        $task = Task::query()->create([
            'title' => 'Assigned task',
            'short_description' => 'A task assigned to the selected user',
            'progress_percentage' => 0,
            'status' => TaskStatus::Draft,
            'requester_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        $task->participantRecords()->create([
            'user_id' => $user->id,
            'role' => TaskParticipantRole::Assignee,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/users?search=searchable.user')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.username', 'searchable.user')
            ->assertJsonPath('data.items.0.assigned_tasks_count', 1)
            ->assertJsonStructure(['data' => ['items' => [['last_activity_at']]]]);

        $this->getJson('/api/v1/users?search=Ali%20Rezaei')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/users?page=2&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonCount(2, 'data.items');

        $this->assertNotNull($admin->fresh()->last_activity_at);
    }

    public function test_admin_can_upload_avatar_and_assign_it_and_an_org_position_to_a_user(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $position = Role::query()->create(['title' => 'کارشناس محصول']);
        Sanctum::actingAs($admin);

        $fileId = $this->post('/api/v1/uploads/images', [
            'file' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file.mime_type', 'image/png')
            ->json('data.file.id');

        $response = $this->postJson('/api/v1/users', [
            'username' => 'avatar.user',
            'first_name' => 'Avatar',
            'last_name' => 'User',
            'mobile' => '09127777777',
            'password' => 'AvatarPass!123',
            'password_confirmation' => 'AvatarPass!123',
            'avatar_file_id' => $fileId,
            'org_position_id' => $position->id,
        ])->assertCreated()
            ->assertJsonPath('data.user.avatar_file_id', $fileId)
            ->assertJsonPath('data.user.avatar.id', $fileId)
            ->assertJsonPath('data.user.org_position.id', $position->id);

        $userId = $response->json('data.user.id');
        $this->assertDatabaseHas('roles', ['id' => $position->id, 'user_id' => $userId]);

        $this->patchJson("/api/v1/users/{$userId}", [
            'avatar_file_id' => null,
            'org_position_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.user.avatar_file_id', null)
            ->assertJsonPath('data.user.avatar', null)
            ->assertJsonPath('data.user.org_position_id', null)
            ->assertJsonPath('data.user.org_position', null);

        $this->assertDatabaseHas('roles', ['id' => $position->id, 'user_id' => null]);
    }

    public function test_username_can_be_used_as_login_identifier(): void
    {
        User::factory()->create([
            'username' => 'login.user',
            'password' => 'LoginPass!123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'LOGIN.USER',
            'password' => 'LoginPass!123',
        ])->assertOk()->assertJsonPath('data.user.username', 'login.user');
    }
}
