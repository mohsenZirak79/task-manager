<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_users_can_view_roles_but_only_admins_can_manage_them(): void
    {
        $regularUser = User::factory()->create(['is_active' => true]);
        $root = Role::query()->create([
            'title' => 'مدیرعامل',
            'user_id' => User::factory()->create()->id,
        ]);
        Sanctum::actingAs($regularUser);

        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson("/api/v1/roles/{$root->id}")->assertOk();
        $this->getJson('/api/v1/roles/available-users')->assertForbidden();
        $this->postJson('/api/v1/roles', [
            'title' => 'غیرمجاز',
            'parent_id' => $root->id,
            'user_id' => User::factory()->create()->id,
        ])->assertForbidden();
        $this->patchJson("/api/v1/roles/{$root->id}", ['title' => 'غیرمجاز'])->assertForbidden();
        $this->deleteJson("/api/v1/roles/{$root->id}")->assertForbidden();

        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
        Sanctum::actingAs($inactiveAdmin);

        $this->getJson('/api/v1/roles')->assertForbidden();

        $admin = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/roles')->assertOk();
    }

    public function test_admin_can_create_a_root_and_a_child_with_a_user(): void
    {
        $this->actingAsAdmin();
        $rootUser = User::factory()->create();

        $rootId = $this->postJson('/api/v1/roles', [
            'title' => 'مدیرعامل',
            'user_id' => $rootUser->id,
            'sort_order' => 1,
        ])->assertCreated()
            ->json('data.role.id');

        $user = User::factory()->create();

        $childId = $this->postJson('/api/v1/roles', [
            'title' => 'مدیر فنی',
            'parent_id' => $rootId,
            'user_id' => $user->id,
            'sort_order' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.role.parent_id', $rootId)
            ->assertJsonPath('data.role.user.id', $user->id)
            ->json('data.role.id');

        $this->assertDatabaseHas('roles', ['id' => $rootId, 'parent_id' => null, 'user_id' => $rootUser->id]);
        $this->assertDatabaseHas('roles', ['id' => $childId, 'parent_id' => $rootId, 'user_id' => $user->id]);
    }

    public function test_a_role_can_be_created_without_a_user_and_with_visible_tabs(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/roles', ['title' => 'بدون مسئول'])
            ->assertCreated()
            ->assertJsonPath('data.role.user_id', null)
            ->assertJsonPath('data.role.visible_tabs', []);
    }

    public function test_an_unassigned_role_can_later_be_assigned_from_user_editing(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $role = Role::query()->create([
            'title' => 'نقش بدون کاربر',
            'visible_tabs' => ['tasks', 'organization'],
        ]);

        $this->patchJson("/api/v1/users/{$user->id}", ['role_id' => $role->id])
            ->assertOk()
            ->assertJsonPath('data.user.role_id', $role->id)
            ->assertJsonPath('data.user.visible_tabs', User::USER_VISIBLE_TABS);

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'user_id' => $user->id]);
    }

    public function test_roles_are_returned_as_an_unlimited_multilevel_tree(): void
    {
        $this->actingAsAdmin();

        $root = Role::query()->create(['title' => 'ریشه', 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        $child = Role::query()->create(['title' => 'سطح دوم', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        $grandchild = Role::query()->create(['title' => 'سطح سوم', 'parent_id' => $child->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        Role::query()->create(['title' => 'سطح چهارم', 'parent_id' => $grandchild->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.children_count', 1)
            ->assertJsonPath('data.roles.0.children.0.children_count', 1)
            ->assertJsonPath('data.roles.0.children.0.children.0.children_count', 1)
            ->assertJsonPath('data.roles.0.children.0.children.0.children.0.title', 'سطح چهارم');
    }

    public function test_a_user_cannot_be_assigned_to_two_roles(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        $rootId = $this->postJson('/api/v1/roles', [
            'title' => 'ریشه',
            'user_id' => $user->id,
        ])->assertCreated()->json('data.role.id');

        $this->postJson('/api/v1/roles', [
            'title' => 'زیرمجموعه',
            'parent_id' => $rootId,
            'user_id' => $user->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_a_role_cannot_be_moved_below_itself_or_its_descendants(): void
    {
        $this->actingAsAdmin();

        $root = Role::query()->create(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        $child = Role::query()->create(['title' => 'فرزند', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $grandchild = Role::query()->create(['title' => 'نوه', 'parent_id' => $child->id, 'user_id' => User::factory()->create()->id]);

        $this->patchJson("/api/v1/roles/{$child->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->patchJson("/api/v1/roles/{$child->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتقال نقش زیر یکی از زیرمجموعه‌های خودش و ایجاد چرخه مجاز نیست.');
    }

    public function test_a_second_root_role_cannot_be_created(): void
    {
        $this->actingAsAdmin();
        Role::query()->create(['title' => 'ریشه اول', 'user_id' => User::factory()->create()->id]);

        $this->postJson('/api/v1/roles', [
            'title' => 'ریشه دوم',
            'user_id' => User::factory()->create()->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ایجاد بیشتر از یک نقش ریشه مجاز نیست.');
    }

    public function test_a_role_with_children_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $root = Role::query()->create(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        Role::query()->create(['title' => 'فرزند', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);

        $this->deleteJson("/api/v1/roles/{$root->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'نقش دارای زیرمجموعه قابل حذف نیست.');

        $this->assertDatabaseHas('roles', ['id' => $root->id]);
    }

    public function test_a_role_can_be_updated_and_a_leaf_can_be_deleted_without_deleting_its_user(): void
    {
        $this->actingAsAdmin();

        $root = Role::query()->create(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        $firstParent = Role::query()->create(['title' => 'والد اول', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $secondParent = Role::query()->create(['title' => 'والد دوم', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $role = Role::query()->create(['title' => 'قدیمی', 'parent_id' => $firstParent->id, 'user_id' => User::factory()->create()->id]);
        $user = User::factory()->create();

        $this->patchJson("/api/v1/roles/{$role->id}", [
            'title' => 'جدید',
            'parent_id' => $secondParent->id,
            'user_id' => $user->id,
            'sort_order' => 7,
        ])->assertOk()
            ->assertJsonPath('data.role.title', 'جدید')
            ->assertJsonPath('data.role.parent_id', $secondParent->id)
            ->assertJsonPath('data.role.sort_order', 7);

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_available_users_excludes_admins_and_assigned_users_and_can_include_the_current_role_user(): void
    {
        $this->actingAsAdmin();

        $assigned = User::factory()->create(['first_name' => 'Assigned']);
        $available = User::factory()->create(['first_name' => 'Available']);
        User::factory()->admin()->create(['first_name' => 'Available Admin']);
        $role = Role::query()->create(['title' => 'ریشه', 'user_id' => $assigned->id]);

        $this->getJson('/api/v1/roles/available-users?search=Available')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $available->id)
            ->assertJsonMissingPath('data.users.0.mobile');

        $this->getJson("/api/v1/roles/available-users?search=Assigned&role_id={$role->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $assigned->id);
    }

    public function test_an_admin_cannot_be_assigned_to_a_role(): void
    {
        $this->actingAsAdmin();
        $roleAdmin = User::factory()->admin()->create();

        $this->postJson('/api/v1/roles', [
            'title' => 'ریشه',
            'user_id' => $roleAdmin->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id')
            ->assertJsonPath('errors.user_id.0', 'کاربر مدیر قابل انتساب به نقش سازمانی نیست.');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
