<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgPositionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_level_limits_round_trip_through_create_update_show_and_tree(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($admin);

        $positionId = $this->postJson('/api/v1/org-positions', [
            'title' => 'مدیریت آزمایشی',
            'user_id' => $member->id,
            'request_up_levels' => 2,
            'assignment_down_levels' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.org_position.request_up_levels', 2)
            ->assertJsonPath('data.org_position.assignment_down_levels', 4)
            ->json('data.org_position.id');

        $this->assertDatabaseHas('org_positions', [
            'id' => $positionId,
            'request_up_levels' => 2,
            'assignment_down_levels' => 4,
        ]);

        $this->patchJson("/api/v1/org-positions/{$positionId}", [
            'request_up_levels' => 1,
            'assignment_down_levels' => 3,
        ])->assertOk()
            ->assertJsonPath('data.org_position.request_up_levels', 1)
            ->assertJsonPath('data.org_position.assignment_down_levels', 3);

        $this->getJson("/api/v1/org-positions/{$positionId}")
            ->assertOk()
            ->assertJsonPath('data.org_position.request_up_levels', 1)
            ->assertJsonPath('data.org_position.assignment_down_levels', 3);

        $this->getJson('/api/v1/org-positions')
            ->assertOk()
            ->assertJsonPath('data.org_positions.0.request_up_levels', 1)
            ->assertJsonPath('data.org_positions.0.assignment_down_levels', 3);
    }
}
