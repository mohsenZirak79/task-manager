<?php

namespace Tests\Feature;

use App\Models\OrgPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgPositionHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_users_can_view_positions_but_only_admins_can_manage_them(): void
    {
        $regularUser = User::factory()->create(['is_active' => true]);
        $root = $this->position([
            'title' => 'مدیرعامل',
            'user_id' => User::factory()->create()->id,
        ]);
        Sanctum::actingAs($regularUser);

        $this->getJson('/api/v1/org-positions')->assertOk();
        $this->getJson("/api/v1/org-positions/{$root->id}")->assertOk();
        $this->getJson('/api/v1/org-positions/available-users')->assertOk();
        $this->postJson('/api/v1/org-positions', [
            'title' => 'غیرمجاز',
            'parent_id' => $root->id,
            'user_id' => User::factory()->create()->id,
        ])->assertForbidden();
        $this->patchJson("/api/v1/org-positions/{$root->id}", ['title' => 'غیرمجاز'])->assertForbidden();
        $this->deleteJson("/api/v1/org-positions/{$root->id}")->assertForbidden();

        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
        Sanctum::actingAs($inactiveAdmin);

        $this->getJson('/api/v1/org-positions')->assertForbidden();

        $admin = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/org-positions')->assertOk();
    }

    public function test_admin_can_create_a_root_and_a_child_with_a_user(): void
    {
        $this->actingAsAdmin();
        $rootUser = User::factory()->create();

        $rootId = $this->postJson('/api/v1/org-positions', [
            'title' => 'مدیرعامل',
            'user_id' => $rootUser->id,
            'sort_order' => 1,
        ])->assertCreated()
            ->json('data.org_position.id');

        $user = User::factory()->create();

        $childId = $this->postJson('/api/v1/org-positions', [
            'title' => 'مدیر فنی',
            'parent_id' => $rootId,
            'user_id' => $user->id,
            'sort_order' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.org_position.parent_id', $rootId)
            ->assertJsonPath('data.org_position.user.id', $user->id)
            ->json('data.org_position.id');

        $this->assertDatabaseHas('org_positions', ['id' => $rootId, 'parent_id' => null]);
        $this->assertDatabaseHas('org_position_user', ['org_position_id' => $rootId, 'user_id' => $rootUser->id]);
        $this->assertDatabaseHas('org_positions', ['id' => $childId, 'parent_id' => $rootId]);
        $this->assertDatabaseHas('org_position_user', ['org_position_id' => $childId, 'user_id' => $user->id]);
    }

    public function test_a_vacant_organizational_position_can_be_created(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/org-positions', ['title' => 'بدون مسئول'])
            ->assertCreated()
            ->assertJsonPath('data.org_position.user_id', null)
            ->assertJsonPath('data.org_position.user', null);
    }

    public function test_positions_are_returned_as_an_unlimited_multilevel_tree(): void
    {
        $this->actingAsAdmin();

        $root = $this->position(['title' => 'ریشه', 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        $child = $this->position(['title' => 'سطح دوم', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        $grandchild = $this->position(['title' => 'سطح سوم', 'parent_id' => $child->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);
        $this->position(['title' => 'سطح چهارم', 'parent_id' => $grandchild->id, 'user_id' => User::factory()->create()->id, 'sort_order' => 1]);

        $this->getJson('/api/v1/org-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data.org_positions')
            ->assertJsonPath('data.org_positions.0.children_count', 1)
            ->assertJsonPath('data.org_positions.0.children.0.children_count', 1)
            ->assertJsonPath('data.org_positions.0.children.0.children.0.children_count', 1)
            ->assertJsonPath('data.org_positions.0.children.0.children.0.children.0.title', 'سطح چهارم');
    }

    public function test_a_user_can_hold_multiple_organizational_positions(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        $rootId = $this->postJson('/api/v1/org-positions', [
            'title' => 'ریشه',
            'user_id' => $user->id,
        ])->assertCreated()->json('data.org_position.id');

        $this->postJson('/api/v1/org-positions', [
            'title' => 'زیرمجموعه',
            'parent_id' => $rootId,
            'user_id' => $user->id,
        ])->assertCreated()
            ->assertJsonPath('data.org_position.user_id', $user->id);

        $this->assertSame(2, $user->orgPositions()->count());
    }

    public function test_a_position_cannot_be_moved_below_itself_or_its_descendants(): void
    {
        $this->actingAsAdmin();

        $root = $this->position(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        $child = $this->position(['title' => 'فرزند', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $grandchild = $this->position(['title' => 'نوه', 'parent_id' => $child->id, 'user_id' => User::factory()->create()->id]);

        $this->patchJson("/api/v1/org-positions/{$child->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->patchJson("/api/v1/org-positions/{$child->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتقال جایگاه زیر یکی از زیرمجموعه‌های خودش و ایجاد چرخه مجاز نیست.');
    }

    public function test_a_second_root_position_cannot_be_created(): void
    {
        $this->actingAsAdmin();
        $this->position(['title' => 'ریشه اول', 'user_id' => User::factory()->create()->id]);

        $this->postJson('/api/v1/org-positions', [
            'title' => 'ریشه دوم',
            'user_id' => User::factory()->create()->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ایجاد بیشتر از یک جایگاه ریشه مجاز نیست.');
    }

    public function test_a_position_with_children_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $root = $this->position(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        $this->position(['title' => 'فرزند', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);

        $this->deleteJson("/api/v1/org-positions/{$root->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'جایگاه دارای زیرمجموعه قابل حذف نیست.');

        $this->assertDatabaseHas('org_positions', ['id' => $root->id]);
    }

    public function test_a_position_can_be_updated_and_a_leaf_can_be_deleted_without_deleting_its_user(): void
    {
        $this->actingAsAdmin();

        $root = $this->position(['title' => 'ریشه', 'user_id' => User::factory()->create()->id]);
        $firstParent = $this->position(['title' => 'والد اول', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $secondParent = $this->position(['title' => 'والد دوم', 'parent_id' => $root->id, 'user_id' => User::factory()->create()->id]);
        $position = $this->position(['title' => 'قدیمی', 'parent_id' => $firstParent->id, 'user_id' => User::factory()->create()->id]);
        $user = User::factory()->create();

        $this->patchJson("/api/v1/org-positions/{$position->id}", [
            'title' => 'جدید',
            'parent_id' => $secondParent->id,
            'user_id' => $user->id,
            'sort_order' => 7,
        ])->assertOk()
            ->assertJsonPath('data.org_position.title', 'جدید')
            ->assertJsonPath('data.org_position.parent_id', $secondParent->id)
            ->assertJsonPath('data.org_position.sort_order', 7);

        $this->deleteJson("/api/v1/org-positions/{$position->id}")->assertOk();

        $this->assertDatabaseMissing('org_positions', ['id' => $position->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_available_users_includes_assigned_users_and_excludes_inactive_users(): void
    {
        $this->actingAsAdmin();

        $assigned = User::factory()->create(['first_name' => 'Assigned']);
        $available = User::factory()->create(['first_name' => 'Available']);
        User::factory()->create(['first_name' => 'Available', 'is_active' => false]);
        $this->position(['title' => 'ریشه', 'user_id' => $assigned->id]);

        $this->getJson('/api/v1/org-positions/available-users?search=Available')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $available->id)
            ->assertJsonMissingPath('data.users.0.mobile');

        $this->getJson('/api/v1/org-positions/available-users?search=Assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $assigned->id);
    }

    private function position(array $attributes): OrgPosition
    {
        $userId = $attributes['user_id'] ?? null;
        unset($attributes['user_id']);
        $position = OrgPosition::query()->create($attributes);
        if ($userId !== null) {
            $position->users()->attach($userId);
        }

        return $position;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
