<?php

namespace Tests\Feature;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Models\AccessRole;
use App\Models\OrgPosition;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiContractV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoints_share_the_extended_resource_and_avatar_flow(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $fileId = $this->post('/api/v1/uploads/images', [
            'file' => UploadedFile::fake()->image('avatar.png', 256, 256),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file.mime_type', 'image/png')
            ->json('data.file.id');

        $position = OrgPosition::query()->create(['title' => 'کارشناس محصول']);
        $userRoleId = AccessRole::query()->where('slug', AccessRoles::USER)->value('id');
        $payload = [
            'username' => 'contract.user',
            'first_name' => 'کاربر',
            'last_name' => 'قرارداد',
            'mobile' => '09121111111',
            'email' => 'contract@example.test',
            'password' => 'ContractPass!123',
            'password_confirmation' => 'ContractPass!123',
            'avatar_file_id' => $fileId,
            'access_role_ids' => [$userRoleId],
            'org_position_ids' => [$position->id],
        ];

        $this->postJson('/api/v1/users', array_diff_key($payload, array_flip(['username', 'password', 'password_confirmation'])))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password', 'password_confirmation']);

        $created = $this->postJson('/api/v1/users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.username', 'contract.user')
            ->assertJsonPath('data.user.avatar.id', $fileId)
            ->assertJsonPath('data.user.tasks_count', 0)
            ->assertJsonCount(1, 'data.user.access_roles')
            ->assertJsonCount(1, 'data.user.org_positions');

        $userId = $created->json('data.user.id');
        $this->assertStandardUserResource($created->json('data.user'));

        $task = Task::query()->create([
            'title' => 'تسک کاربر',
            'short_description' => 'برای بررسی tasks_count',
            'status' => TaskStatus::Draft,
            'requester_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        $task->participantRecords()->create([
            'user_id' => $userId,
            'role' => TaskParticipantRole::Assignee,
        ]);

        $listed = $this->getJson('/api/v1/users?search=contract.user&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.tasks_count', 1)
            ->assertJsonPath('data.items.0.username', 'contract.user');
        $this->assertStandardUserResource($listed->json('data.items.0'));

        $this->getJson('/api/v1/users?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $shown = $this->getJson("/api/v1/users/{$userId}")->assertOk();
        $this->assertStandardUserResource($shown->json('data.user'));

        $updated = $this->patchJson("/api/v1/users/{$userId}", [
            'username' => 'contract.user',
            'avatar_file_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.user.username', 'contract.user')
            ->assertJsonPath('data.user.avatar', null);
        $this->assertStandardUserResource($updated->json('data.user'));

        $deactivated = $this->postJson("/api/v1/users/{$userId}/deactivate")->assertOk();
        $this->assertStandardUserResource($deactivated->json('data.user'));

        $activated = $this->postJson("/api/v1/users/{$userId}/activate")->assertOk();
        $this->assertStandardUserResource($activated->json('data.user'));

        $reset = $this->postJson("/api/v1/users/{$userId}/reset-password", [
            'password' => 'NewPassword!123',
            'password_confirmation' => 'NewPassword!123',
        ])->assertOk();
        $this->assertStandardUserResource($reset->json('data.user'));

        $this->postJson('/api/v1/users', [
            ...$payload,
            'mobile' => '09122222222',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->patchJson("/api/v1/users/{$userId}", ['avatar_file_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar_file_id');

        $this->assertNotNull($admin->fresh()->last_activity_at);
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'org_code' => '100001',
            'username' => 'admin.user',
            'first_name' => 'مدیر',
            'last_name' => 'سیستم',
            'mobile' => '09120000000',
            'password' => 'AdminPass!123',
            'is_active' => true,
        ]);
        $roleId = AccessRole::query()->where('slug', AccessRoles::SUPER_ADMIN)->value('id');
        $user->accessRoles()->attach($roleId);

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function assertStandardUserResource(array $user): void
    {
        foreach ([
            'id', 'org_code', 'username', 'first_name', 'last_name', 'mobile', 'email',
            'avatar_file_id', 'avatar', 'is_active', 'access_roles', 'permissions',
            'org_positions', 'last_login_at', 'last_activity_at', 'tasks_count',
            'created_at', 'updated_at',
        ] as $field) {
            $this->assertArrayHasKey($field, $user, "Missing standard user field: {$field}");
        }
    }
}
