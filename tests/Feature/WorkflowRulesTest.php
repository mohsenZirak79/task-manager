<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Database\Seeders\ProjectTagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowRulesTest extends TestCase
{
    use RefreshDatabase;

    private function position(User $user, ?OrgPosition $parent = null, ?int $depth = null): OrgPosition
    {
        $position = OrgPosition::create(['title' => 'Position', 'parent_id' => $parent?->id, 'assignment_down_levels' => $depth]);
        $position->users()->attach($user);

        return $position;
    }

    private function tree(): array
    {
        [$root, $actor, $peer, $same, $child, $grandchild, $foreign] = User::factory()->count(7)->create()->all();
        $rootPosition = $this->position($root);
        $actorPosition = $this->position($actor, $rootPosition, 1);
        $this->position($peer, $rootPosition);
        $this->position($same, $rootPosition);
        $childPosition = $this->position($child, $actorPosition);
        $this->position($grandchild, $childPosition);
        $foreignRoot = OrgPosition::create(['title' => 'Other root']);
        $this->position($foreign, $foreignRoot);

        return [$root, $actor, $peer, $same, $child, $grandchild, $foreign];
    }

    private function payload(User $actor, User $target): array
    {
        return [
            'submission_type' => 'request', 'title' => 'Request', 'short_description' => 'Summary',
            'duration_minutes' => 60, 'due_date' => now()->addWeek()->toDateString(),
            'assignee_ids' => [$target->id], 'follower_ids' => [$actor->id],
            'supervisor_ids' => [$actor->id], 'tags' => ['work'],
        ];
    }

    public function test_eligible_and_direct_request_create_agree_for_peers_descendants_and_forbidden_targets(): void
    {
        [$root, $actor, $peer, $same, $child, $grandchild, $foreign] = $this->tree();
        Sanctum::actingAs($actor);
        foreach (['', '?submission_type=request'] as $query) {
            $data = $this->getJson('/api/v1/tasks/eligible-users'.$query)->assertOk()->json('data');
            $this->assertEqualsCanonicalizing([$peer->id, $same->id, $child->id], array_column($data['request_targets'], 'id'));
            if ($query === '') {
                $this->assertSame([$actor->id], array_column($data['assignment_targets'], 'id'));
            }
            $this->assertContains($root->id, array_column($data['participants'], 'id'));
            $this->assertNotContains($peer->id, array_column($data['participants'], 'id'));
        }
        foreach ([$peer, $same, $child] as $target) {
            foreach ([false, true] as $submit) {
                $this->postJson('/api/v1/tasks', [...$this->payload($actor, $target), 'submit' => $submit])
                    ->assertCreated()->assertJsonPath('data.task.submission_type', 'request');
            }
        }
        foreach ([$root, $grandchild, $foreign] as $target) {
            foreach ([false, true] as $submit) {
                $this->postJson('/api/v1/tasks', [...$this->payload($actor, $target), 'submit' => $submit])->assertForbidden();
            }
        }
        $this->postJson('/api/v1/tasks', [...$this->payload($actor, $child), 'follower_ids' => [$peer->id]])->assertForbidden();
    }

    public function test_request_edit_and_final_submission_revalidate_direction_without_changing_old_visibility(): void
    {
        [$root, $actor, $peer, $same, $child] = $this->tree();
        Sanctum::actingAs($actor);
        $id = $this->postJson('/api/v1/tasks', $this->payload($actor, $child))->assertCreated()->json('data.task.id');
        $this->patchJson("/api/v1/tasks/$id", ['assignee_ids' => [$peer->id]])->assertOk();
        $this->patchJson("/api/v1/tasks/$id", ['assignee_ids' => [$root->id]])->assertForbidden();
        $child->orgPositions()->first()->update(['parent_id' => null]);
        $task = Task::findOrFail($id);
        $task->participantRecords()->where('role', 'assignee')->update(['user_id' => $root->id]);
        $this->getJson("/api/v1/tasks/$id")->assertOk();
        Sanctum::actingAs($root);
        $this->getJson("/api/v1/tasks/$id")->assertOk();
        Sanctum::actingAs($actor);
        $this->patchJson("/api/v1/tasks/$id", ['title' => 'Changed'])->assertForbidden();
        $this->patchJson("/api/v1/tasks/$id", ['submit' => true])->assertForbidden();
        $this->patchJson("/api/v1/tasks/$id", ['assignee_ids' => [$same->id], 'submit' => true])
            ->assertOk()->assertJsonPath('data.task.status', 'pending_approval');
    }

    public function test_super_admin_request_exception_and_personal_assignment_are_preserved(): void
    {
        [$root, $actor] = $this->tree();
        $admin = User::factory()->admin()->create();
        foreach ([$actor, $admin] as $creator) {
            Sanctum::actingAs($creator);
            $this->postJson('/api/v1/tasks', [...$this->payload($creator, $root), 'submission_type' => 'assignment'])->assertUnprocessable();
            $this->postJson('/api/v1/tasks', [...$this->payload($creator, $creator), 'submission_type' => 'assignment', 'submit' => true])->assertCreated();
            $targets = $this->getJson('/api/v1/tasks/eligible-users')->assertOk()->json('data.assignment_targets');
            $this->assertSame([$creator->id], array_column($targets, 'id'));
        }
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/tasks', [...$this->payload($admin, $root), 'submit' => true])->assertCreated();
    }

    public function test_unrelated_root_positions_are_not_peers_and_multiple_positions_do_not_allow_unrelated_branches(): void
    {
        [$root, $actor, $peer, $same, $child, $grandchild, $foreign] = $this->tree();
        $otherRoot = User::factory()->create();
        $this->position($otherRoot);
        Sanctum::actingAs($root);
        $this->postJson('/api/v1/tasks', $this->payload($root, $otherRoot))->assertForbidden();
        $targets = $this->getJson('/api/v1/tasks/eligible-users')->assertOk()->json('data.request_targets');
        $this->assertNotContains($otherRoot->id, array_column($targets, 'id'));
        $this->position($actor, $child->orgPositions()->firstOrFail(), 0);
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/tasks', [...$this->payload($actor, $grandchild), 'submit' => true])->assertCreated();
        $this->postJson('/api/v1/tasks', $this->payload($actor, $foreign))->assertForbidden();
    }

    private function assignment(User $actor, string $status = 'ready_to_start'): Task
    {
        $task = Task::create([
            'submission_type' => 'assignment', 'title' => 'Task', 'short_description' => 'Summary',
            'duration_minutes' => 60, 'due_date' => now()->addWeek(), 'status' => $status,
            'requester_id' => $actor->id, 'created_by' => $actor->id, 'registered_at' => now(),
        ]);
        $task->participantRecords()->create(['role' => 'assignee', 'user_id' => $actor->id]);

        return $task;
    }

    public function test_requests_cannot_assign_the_actor_even_for_super_admin_or_existing_drafts(): void
    {
        [, $actor, $peer] = $this->tree();
        $admin = User::factory()->admin()->create();
        foreach ([$actor, $admin] as $creator) {
            Sanctum::actingAs($creator);
            foreach (['', '?submission_type=request'] as $query) {
                $targets = $this->getJson('/api/v1/tasks/eligible-users'.$query)->assertOk()->json('data.request_targets');
                $this->assertNotContains($creator->id, array_column($targets, 'id'));
            }
            foreach ([false, true] as $submit) {
                $this->postJson('/api/v1/tasks', [...$this->payload($creator, $creator), 'submit' => $submit])
                    ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
                $this->postJson('/api/v1/tasks', [...$this->payload($creator, $peer), 'assignee_ids' => [$peer->id, $creator->id], 'submit' => $submit])
                    ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
            }
            $id = $this->postJson('/api/v1/tasks', $this->payload($creator, $peer))->assertCreated()->json('data.task.id');
            $this->patchJson("/api/v1/tasks/$id", ['assignee_ids' => [$creator->id]])
                ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
            $task = Task::findOrFail($id);
            // Legacy self-assigned requests must also be rejected at final submission.
            $task->participantRecords()->where('role', 'assignee')->update(['user_id' => $creator->id]);
            $this->patchJson("/api/v1/tasks/$id", ['submit' => true])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
            $this->assertSame(TaskStatus::Draft, $task->fresh()->status);
            $this->postJson('/api/v1/tasks', [...$this->payload($creator, $creator), 'submission_type' => 'assignment', 'submit' => true])
                ->assertCreated();
        }
    }

    public function test_returned_tasks_disappear_from_recipient_lists_until_resubmitted(): void
    {
        [, $creator, $recipient] = $this->tree();
        foreach (['request-revision', 'reject'] as $action) {
            Sanctum::actingAs($creator);
            $id = $this->postJson('/api/v1/tasks', [...$this->payload($creator, $recipient), 'submit' => true])
                ->assertCreated()->json('data.task.id');
            Sanctum::actingAs($recipient);
            $this->getJson('/api/v1/tasks')->assertOk()->assertJsonFragment(['id' => $id]);
            $this->postJson("/api/v1/tasks/$id/$action", ['reason' => 'Please revise'])->assertOk();
            foreach (['', '?scope=involved', '?scope=assigned_to_me', '?scope=action_required'] as $scope) {
                $items = $this->getJson('/api/v1/tasks'.$scope)->assertOk()->json('data.items');
                $this->assertNotContains($id, array_column($items, 'id'));
            }
            Sanctum::actingAs($creator);
            $this->getJson('/api/v1/tasks?submission_type=request&scope=created_by_me')->assertOk()->assertJsonFragment(['id' => $id]);
            $this->patchJson("/api/v1/tasks/$id", ['title' => 'Fixed', 'submit' => false])
                ->assertOk()->assertJsonPath('data.task.status', 'draft');
            Sanctum::actingAs($recipient);
            $items = $this->getJson('/api/v1/tasks')->assertOk()->json('data.items');
            $this->assertNotContains($id, array_column($items, 'id'));
            Sanctum::actingAs($creator);
            $this->patchJson("/api/v1/tasks/$id", ['submit' => true])->assertOk()->assertJsonPath('data.task.status', 'pending_approval');
            Sanctum::actingAs($recipient);
            foreach (['', '?scope=involved', '?scope=assigned_to_me', '?scope=action_required'] as $scope) {
                $items = $this->getJson('/api/v1/tasks'.$scope)->assertOk()->json('data.items');
                $this->assertContains($id, array_column($items, 'id'));
            }
        }
    }

    public function test_project_tags_are_seeded_without_duplicates_or_removing_existing_tags(): void
    {
        $existing = Tag::create(['title' => 'Custom project']);
        $this->seed(ProjectTagSeeder::class);
        $this->seed(ProjectTagSeeder::class);
        $this->assertDatabaseCount('tags', count(ProjectTagSeeder::TITLES) + 1);
        $this->assertDatabaseHas('tags', ['id' => $existing->id, 'title' => 'Custom project']);
        foreach (ProjectTagSeeder::TITLES as $title) {
            $this->assertDatabaseHas('tags', ['title' => $title]);
        }
    }

    public function test_planning_patch_preserves_ids_progress_status_and_history_and_deletes_only_explicitly(): void
    {
        [, $actor] = $this->tree();
        Sanctum::actingAs($actor);
        foreach (['ready_to_start', 'in_progress'] as $status) {
            $task = $this->assignment($actor, $status);
            $first = $task->planningItems()->create(['title' => 'One', 'weight' => 1, 'progress_percentage' => 60, 'sort_order' => 0]);
            $second = $task->planningItems()->create(['title' => 'Two', 'weight' => 1, 'progress_percentage' => 20, 'sort_order' => 1]);
            $registered = $task->registered_at->toISOString();
            $this->getJson("/api/v1/tasks/{$task->id}")->assertOk()
                ->assertJsonPath('data.task.allowed_actions.edit', false)
                ->assertJsonPath('data.task.allowed_actions.update_planning', true);
            $data = $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => [
                ['id' => $first->id, 'title' => 'Updated', 'weight' => 3],
                ['title' => 'New', 'weight' => 1],
            ]])->assertOk()->assertJsonPath('data.task.status', $status)
                ->assertJsonPath('data.task.progress_percentage', 40)
                ->assertJsonPath('data.task.registered_at', $registered)->json('data.task');
            $this->assertCount(3, $data['planning_items']);
            $this->assertSame(60, $first->fresh()->progress_percentage);
            $this->assertSame(20, $second->fresh()->progress_percentage);
            $this->assertSame(0, $task->planningItems()->reorder()->latest('id')->first()->progress_percentage);
            $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => []])->assertOk()->assertJsonCount(3, 'data.task.planning_items');
            $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['delete_planning_item_ids' => [$second->id]])
                ->assertOk()->assertJsonCount(2, 'data.task.planning_items')->assertJsonPath('data.task.progress_percentage', 45);
            $this->assertSame(0, $task->workflowHistory()->count());
        }
    }

    public function test_planning_is_scoped_validated_and_atomic(): void
    {
        [, $actor, $other] = $this->tree();
        $task = $this->assignment($actor);
        $foreign = $this->assignment($other)->planningItems()->create(['title' => 'Foreign', 'weight' => 1]);
        $item = $task->planningItems()->create(['title' => 'Original', 'weight' => 1, 'progress_percentage' => 50]);
        Sanctum::actingAs($actor);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'No'])->assertForbidden();
        foreach ([['title' => 'No'], ['submit' => true], ['planning_items' => [['id' => $item->id, 'title' => 'No', 'weight' => 1, 'progress_percentage' => 99]]]] as $payload) {
            $this->patchJson("/api/v1/tasks/{$task->id}/planning", $payload)->assertUnprocessable();
        }
        foreach ([['planning_items' => [['id' => $foreign->id, 'title' => 'No', 'weight' => 1]]], ['delete_planning_item_ids' => [$foreign->id]], ['planning_items' => [['id' => $item->id, 'title' => 'No', 'weight' => 1]], 'delete_planning_item_ids' => [$item->id]]] as $payload) {
            $this->patchJson("/api/v1/tasks/{$task->id}/planning", $payload)->assertUnprocessable();
        }
        $this->assertSame('Original', $item->fresh()->title);
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => []])->assertForbidden();
        Sanctum::actingAs($actor);
        foreach (['draft', 'pending_approval', 'revision_requested', 'pending_completion_approval', 'completed', 'closed', 'rejected', 'not_completed'] as $status) {
            $task->update(['status' => $status]);
            $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => []])->assertForbidden();
            $this->getJson("/api/v1/tasks/{$task->id}")->assertOk()->assertJsonPath('data.task.allowed_actions.update_planning', false);
        }
        $task->update(['status' => 'in_progress', 'submission_type' => 'request']);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning", ['planning_items' => []])->assertForbidden();
    }

    public function test_revision_save_and_submit_are_atomic_on_same_record_and_allow_unchanged_type(): void
    {
        [, $actor, $peer] = $this->tree();
        Sanctum::actingAs($actor);
        foreach (['assignment', 'request'] as $type) {
            $target = $type === 'assignment' ? $actor : $peer;
            $id = $this->postJson('/api/v1/tasks', [...$this->payload($actor, $target), 'submission_type' => $type, 'submit' => true])->assertCreated()->json('data.task.id');
            Sanctum::actingAs($target);
            $this->postJson("/api/v1/tasks/$id/request-revision", ['reason' => 'Revise'])->assertOk();
            Sanctum::actingAs($actor);
            $task = Task::findOrFail($id);
            $historyCount = $task->workflowHistory()->count();
            // Service-level failure happens after mutations, not just FormRequest validation.
            $task->update(['due_date' => now()->subDay()]);
            $this->patchJson("/api/v1/tasks/$id", ['submission_type' => $type, 'title' => 'Must rollback', 'tags' => ['changed'], 'submit' => true])->assertStatus(422);
            $this->assertSame('Request', $task->fresh()->title);
            $this->assertSame(TaskStatus::RevisionRequested, $task->fresh()->status);
            $this->assertSame('work', $task->tags()->first()->title);
            $this->assertSame($historyCount, $task->workflowHistory()->count());
            $this->patchJson("/api/v1/tasks/$id", ['submission_type' => $type === 'assignment' ? 'request' : 'assignment', 'assignee_ids' => [$actor->id]])->assertStatus(409);
            $this->patchJson("/api/v1/tasks/$id", ['submission_type' => $type, 'title' => 'Saved', 'submit' => false])->assertOk();
            $this->patchJson("/api/v1/tasks/$id", ['submission_type' => $type, 'title' => 'Sent', 'due_date' => now()->addWeek()->toDateString(), 'submit' => true])
                ->assertOk()->assertJsonPath('data.task.id', $id)->assertJsonPath('data.task.status', 'pending_approval');
            $this->assertSame('Sent', $task->fresh()->title);
        }
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_request_revision_can_save_and_send_in_one_call_without_draft_round_trip(): void
    {
        [, $actor, $peer] = $this->tree();
        foreach (['revision_requested', 'rejected'] as $status) {
            Sanctum::actingAs($actor);
            $id = $this->postJson('/api/v1/tasks', $this->payload($actor, $peer))->assertCreated()->json('data.task.id');
            Task::findOrFail($id)->update(['status' => $status, 'rejection_reason' => 'Fix']);
            $this->getJson("/api/v1/tasks/$id")->assertOk()->assertJsonPath('data.task.allowed_actions.submit', true);
            $this->patchJson("/api/v1/tasks/$id", ['submission_type' => 'request', 'title' => 'Fixed', 'submit' => true])
                ->assertOk()->assertJsonPath('data.task.status', 'pending_approval')->assertJsonPath('data.task.rejection_reason', null);
            $this->assertDatabaseHas('task_workflow_histories', ['task_id' => $id, 'action' => 'revision_edit_started', 'from_status' => $status, 'to_status' => 'draft']);
        }
    }

    private function meeting(User $creator): Meeting
    {
        Sanctum::actingAs($creator);
        $id = $this->postJson('/api/v1/meetings', [
            'title' => 'Meeting', 'location' => 'Office', 'meeting_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'chairman_user_id' => $creator->id, 'secretary_user_id' => $creator->id,
            'agenda_items' => [['title' => 'Agenda', 'sort_order' => 0]],
        ])->assertCreated()->json('data.meeting.id');

        return Meeting::findOrFail($id);
    }

    public function test_creator_and_authorized_manager_edit_before_start_preserving_relations_and_submission_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
        [$creator, $outsider] = User::factory()->count(2)->create()->all();
        $admin = User::factory()->create();
        $admin->accessRoles()->attach(AccessRole::where('slug', AccessRoles::ADMIN)->firstOrFail());
        $meeting = $this->meeting($creator);
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Draft updated'])->assertOk();
        $this->postJson("/api/v1/meetings/{$meeting->id}/submit")->assertOk();
        $agenda = $meeting->agendaItems()->firstOrFail();
        $resolution = $meeting->resolutions()->create(['title' => 'Resolution', 'agenda_item_id' => $agenda->id, 'created_by' => $creator->id, 'resolution_type' => 'report']);
        $submitted = $meeting->fresh()->submitted_at->toISOString();
        foreach ([$creator, $admin] as $actor) {
            Sanctum::actingAs($actor);
            $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Scheduled updated', 'submit' => true])
                ->assertOk()->assertJsonPath('data.meeting.status', 'scheduled')->assertJsonPath('data.meeting.submitted_at', $submitted)
                ->assertJsonPath('data.meeting.resolutions.0.id', $resolution->id)
                ->assertJsonPath('data.meeting.resolutions.0.agenda_item_id', $agenda->id);
        }
        Sanctum::actingAs($outsider);
        $meeting->attendees()->attach($outsider);
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'No'])->assertForbidden();
        Sanctum::actingAs($creator);
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['agenda_items' => []])->assertUnprocessable();
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['start_time' => null])->assertStatus(422);
        $this->assertNotNull($meeting->fresh()->start_time);
        $this->assertSame($agenda->id, $resolution->fresh()->agenda_item_id);
    }

    public function test_meeting_edit_respects_exact_start_timezone_and_final_states_even_for_super_admin(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Tehran'));
        $creator = User::factory()->create();
        $admin = User::factory()->create();
        $admin->accessRoles()->attach(AccessRole::where('slug', AccessRoles::ADMIN)->firstOrFail());
        $meeting = $this->meeting($creator);
        $meeting->update(['meeting_date' => '2026-10-05', 'start_time' => '10:01']);
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Before'])->assertOk();
        $this->patchJson("/api/v1/meetings/{$meeting->id}", ['start_time' => '09:59'])->assertStatus(409);
        $this->assertSame('10:01', $meeting->fresh()->start_time->format('H:i'));
        foreach (['draft', 'scheduled', 'completed', 'cancelled'] as $status) {
            $meeting->update(['status' => $status, 'start_time' => '10:00']);
            foreach ([$creator, $admin] as $actor) {
                Sanctum::actingAs($actor);
                $this->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'No'])->assertForbidden();
            }
        }
    }
}
