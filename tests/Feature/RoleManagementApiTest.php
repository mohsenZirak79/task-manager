<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_two_routes_require_a_bearer_token(): void
    {
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->getJson('/api/v1/role-relations')->assertUnauthorized();
        $this->getJson('/api/v1/user-relation-exceptions')->assertUnauthorized();
    }

    public function test_role_assignment_relations_and_exceptions_work_with_a_bearer_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $parent = $this->withHeaders($headers)->postJson('/api/v1/roles', ['name' => 'Parent', 'level' => 1])->assertCreated()->json('data.id');
        $child = $this->withHeaders($headers)->postJson('/api/v1/roles', ['name' => 'Child', 'level' => 2])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->getJson('/api/v1/roles?search=Parent&level=1&is_active=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $parent);

        $this->withHeaders($headers)->postJson("/api/v1/users/{$user->id}/roles", ['role_id' => $child])
            ->assertCreated()->assertJsonPath('data.is_active', true);

        $this->withHeaders($headers)->postJson('/api/v1/role-relations', ['parent_role_id' => $parent, 'child_role_id' => $child, 'relation_type' => 'top_down'])
            ->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/user-relation-exceptions', ['from_user_id' => $user->id, 'to_user_id' => $other->id, 'permission_type' => 'allow'])
            ->assertCreated();

        $this->withHeaders($headers)->deleteJson("/api/v1/users/{$user->id}/roles/{$child}")
            ->assertOk()->assertJsonPath('data.is_active', false);
    }
}
