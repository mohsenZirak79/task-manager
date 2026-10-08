<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskListOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $creator, array $attributes = []): Task
    {
        return Task::create([
            'title' => 'List classification',
            'submission_type' => 'request',
            'status' => 'pending_approval',
            'created_by' => $creator->id,
            'requester_id' => $creator->id,
            ...$attributes,
        ]);
    }

    public function test_tasks_list_requires_a_responsibility_even_when_actor_created_the_request(): void
    {
        [$actor, $other] = User::factory()->count(2)->create()->all();
        $outgoing = $this->task($actor);
        $outgoing->participantRecords()->create(['role' => 'assignee', 'user_id' => $other->id]);
        $requesterOnly = $this->task($other, ['requester_id' => $actor->id]);
        $equipmentOnly = $this->task($other, ['equipment_provider_user_id' => $actor->id]);
        $expected = [];
        foreach (['assignee', 'follower', 'supervisor'] as $role) {
            $task = $this->task($other);
            $task->participantRecords()->create(['role' => $role, 'user_id' => $actor->id]);
            $expected[] = $task->id;
        }
        $expected[] = $this->task($other, ['financial_provider_user_id' => $actor->id])->id;
        $ownWithRole = $this->task($actor);
        $ownWithRole->participantRecords()->create(['role' => 'supervisor', 'user_id' => $actor->id]);
        $expected[] = $ownWithRole->id;
        $ownAssignment = $this->task($actor, ['submission_type' => 'assignment']);
        $ownAssignment->participantRecords()->create(['role' => 'assignee', 'user_id' => $actor->id]);
        $expected[] = $ownAssignment->id;

        Sanctum::actingAs($actor);
        foreach (['/api/v1/tasks', '/api/v1/tasks?scope=involved'] as $url) {
            $items = $this->getJson($url)->assertOk()->assertJsonPath('data.meta.total', 6)->json('data.items');
            $this->assertEqualsCanonicalizing($expected, array_column($items, 'id'));
        }
        $pages = [];
        for ($page = 1; $page <= 3; $page++) {
            $items = $this->getJson("/api/v1/tasks?scope=involved&per_page=2&page=$page")
                ->assertOk()->assertJsonPath('data.meta.total', 6)->json('data.items');
            $pages = [...$pages, ...array_column($items, 'id')];
        }
        $this->assertEqualsCanonicalizing($expected, $pages);
        foreach (['', '&scope=created_by_me'] as $scope) {
            $items = $this->getJson('/api/v1/tasks?submission_type=request'.$scope)
                ->assertOk()->assertJsonPath('data.meta.total', 3)->json('data.items');
            $this->assertEqualsCanonicalizing([$outgoing->id, $requesterOnly->id, $ownWithRole->id], array_column($items, 'id'));
        }
        // Outgoing requests remain readable/editable through their detail route.
        $this->getJson("/api/v1/tasks/{$outgoing->id}")->assertOk();
        $this->assertNotContains($equipmentOnly->id, $expected);
    }

    public function test_super_admin_personal_tasks_list_does_not_include_creator_only_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $task = $this->task($admin);
        $task->participantRecords()->create(['role' => 'assignee', 'user_id' => $other->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/tasks?scope=involved')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/tasks?submission_type=request')->assertOk()->assertJsonPath('data.items.0.id', $task->id);
        $this->getJson('/api/v1/tasks?scope=all')->assertOk()->assertJsonPath('data.items.0.id', $task->id);
    }
}
