<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskExecutionTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private User $leader;

    private User $member;

    private User $junior;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->sender, $this->leader, $this->member, $this->junior, $this->outsider] = User::factory()->count(5)->create()->all();
        $root = OrgPosition::create(['title' => 'مدیرعامل']);
        $department = OrgPosition::create(['title' => 'مدیر واحد', 'parent_id' => $root->id, 'assignment_down_levels' => 1]);
        $child = OrgPosition::create(['title' => 'کارشناس', 'parent_id' => $department->id]);
        $grandchild = OrgPosition::create(['title' => 'دستیار', 'parent_id' => $child->id]);
        $other = OrgPosition::create(['title' => 'واحد نامرتبط', 'parent_id' => $root->id]);
        foreach ([[$this->sender, $root], [$this->leader, $department], [$this->member, $child], [$this->junior, $grandchild], [$this->outsider, $other]] as [$user, $position]) {
            $user->orgPositions()->attach($position);
        }
    }

    private function task(string $status = 'ready_to_start', string $type = 'assignment'): Task
    {
        $task = Task::create(['title' => 'اجرای گروهی', 'short_description' => 'تست', 'created_by' => $this->sender->id, 'requester_id' => $this->sender->id, 'status' => $status, 'submission_type' => $type]);
        $task->participantRecords()->create(['user_id' => $this->leader->id, 'role' => 'assignee']);
        $task->teamMembers()->attach([$this->member->id, $this->junior->id]);

        return $task;
    }

    public function test_team_options_include_all_descendant_levels_but_not_peers_parents_or_inactive_users(): void
    {
        Sanctum::actingAs($this->leader);
        $ids = $this->getJson('/api/v1/tasks/team-users?leader_user_id='.$this->leader->id)->assertOk()->json('data.items.*.id');
        $this->assertEqualsCanonicalizing([$this->member->id, $this->junior->id], $ids);
        $this->junior->update(['is_active' => false]);
        $this->getJson('/api/v1/tasks/team-users?leader_user_id='.$this->leader->id)->assertOk()->assertJsonCount(1, 'data.items');
        $foreign = OrgPosition::create(['title' => 'سازمان نامرتبط']);
        $this->outsider->orgPositions()->sync([$foreign->id]);
        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/v1/tasks/team-users?leader_user_id='.$this->leader->id)->assertForbidden();
    }

    public function test_creation_validates_team_and_planning_assignment_and_drafts_remain_private(): void
    {
        Sanctum::actingAs($this->leader);
        $payload = ['submission_type' => 'assignment', 'title' => 'تیم', 'assignee_ids' => [$this->leader->id], 'team_member_ids' => [$this->outsider->id]];
        $this->postJson('/api/v1/tasks', $payload)->assertUnprocessable()->assertJsonValidationErrors('team_member_ids');
        $payload['team_member_ids'] = [$this->member->id, $this->junior->id];
        $payload['planning_items'] = [['title' => 'کار', 'weight' => 1, 'assignee_user_id' => $this->outsider->id]];
        $this->postJson('/api/v1/tasks', $payload)->assertUnprocessable()->assertJsonValidationErrors('planning_items.0.assignee_user_id');
        $payload['planning_items'][0]['assignee_user_id'] = $this->member->id;
        $id = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->assertJsonPath('data.task.planning_items.0.assignee_user_id', $this->member->id)->json('data.task.id');
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/tasks/'.$id)->assertForbidden();
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->postJson('/api/v1/tasks/'.$id.'/acknowledge')->assertForbidden();
    }

    public function test_team_member_only_acknowledges_and_updates_own_work_after_leader_starts(): void
    {
        $task = $this->task();
        $mine = $task->planningItems()->create(['title' => 'کار من', 'weight' => 1, 'assignee_user_id' => $this->member->id]);
        $other = $task->planningItems()->create(['title' => 'کار دیگری', 'weight' => 1, 'assignee_user_id' => $this->junior->id]);
        $url = '/api/v1/tasks/'.$task->id;
        Sanctum::actingAs($this->member);
        $actions = $this->getJson($url)->assertOk()->json('data.task.allowed_actions');
        $this->assertTrue($actions['acknowledge']);
        foreach (['approve', 'reject', 'start', 'update_progress', 'update_planning', 'update_planning_progress', 'transfer', 'manage_team', 'comment'] as $action) {
            $this->assertFalse($actions[$action], $action);
        }
        $this->getJson('/api/v1/tasks?scope=assigned_to_me')->assertOk()->assertJsonPath('data.items.0.id', $task->id);
        $this->postJson($url.'/acknowledge')->assertOk();
        $viewed = $task->teamMembers()->whereKey($this->member->id)->first()->pivot->viewed_at;
        $this->postJson($url.'/acknowledge')->assertOk();
        $this->assertSame($viewed, $task->teamMembers()->whereKey($this->member->id)->first()->pivot->viewed_at);
        $this->assertSame(1, $task->workflowHistory()->where('action', 'execution_viewed')->count());
        $this->postJson($url.'/status', ['status' => 'in_progress'])->assertForbidden();
        $this->patchJson($url.'/planning-items/'.$mine->id, ['progress_percentage' => 50])->assertForbidden();
        Sanctum::actingAs($this->leader);
        $this->postJson($url.'/status', ['status' => 'in_progress'])->assertOk();
        Sanctum::actingAs($this->member);
        $this->patchJson($url.'/planning-items/'.$mine->id, ['progress_percentage' => 50])->assertOk()->assertJsonPath('data.task.progress_percentage', 25);
        $this->patchJson($url.'/planning-items/'.$other->id, ['progress_percentage' => 100])->assertForbidden();
        $this->postJson($url.'/progress', ['progress_percentage' => 100])->assertForbidden();
        $this->postJson($url.'/reject', ['reason' => 'رد'])->assertForbidden();
        $this->patchJson($url.'/planning', ['planning_items' => []])->assertForbidden();
        $this->patchJson($url.'/team', ['team_member_ids' => []])->assertForbidden();
        $this->postJson($url.'/comments', ['body' => 'غیرمجاز'])->assertForbidden();
        $this->assertSame(0, $other->fresh()->progress_percentage);
        Sanctum::actingAs($this->outsider);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url.'/acknowledge')->assertForbidden();
    }

    public function test_transfer_replaces_leader_keeps_sender_and_follower_and_requires_view_before_start_for_both_types(): void
    {
        foreach (['assignment', 'request'] as $type) {
            $task = $this->task('pending_approval', $type);
            $work = $task->planningItems()->create(['title' => 'کار حفظ‌شده', 'weight' => 3, 'assignee_user_id' => $this->member->id]);
            $number = $task->task_number;
            $url = '/api/v1/tasks/'.$task->id;
            Sanctum::actingAs($this->leader);
            $this->postJson($url.'/transfer', ['assignee_user_id' => $this->member->id, 'team_member_ids' => [$this->junior->id]])->assertOk();
            $this->assertSame($number, $task->fresh()->task_number);
            $this->assertSame($this->sender->id, $task->fresh()->created_by);
            $this->assertSame($this->sender->id, $task->fresh()->requester_id);
            $this->assertSame([$this->member->id], $task->assignees()->pluck('users.id')->all());
            $this->assertTrue($task->followers()->whereKey($this->leader->id)->exists());
            $this->assertSame('کار حفظ‌شده', $work->fresh()->title);
            $this->assertNull($work->fresh()->assignee_user_id);
            Sanctum::actingAs($this->sender);
            $this->postJson($url.'/status', ['status' => 'in_progress'])->assertForbidden();
            Sanctum::actingAs($this->member);
            $this->getJson($url)->assertOk()->assertJsonPath('data.task.allowed_actions.acknowledge', true)->assertJsonPath('data.task.allowed_actions.start', false)->assertJsonPath('data.task.allowed_actions.reject', false);
            $this->postJson($url.'/approve')->assertForbidden();
            $this->postJson($url.'/reject', ['reason' => 'رد'])->assertForbidden();
            $this->postJson($url.'/request-revision', ['reason' => 'اصلاح'])->assertForbidden();
            $this->postJson($url.'/status', ['status' => 'in_progress'])->assertForbidden();
            $this->postJson($url.'/acknowledge')->assertOk()->assertJsonPath('data.task.allowed_actions.start', true);
            $this->postJson($url.'/status', ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.task.status', 'in_progress');
        }
    }

    public function test_transfer_keeps_the_meeting_resolution_and_compatible_planning_assignments(): void
    {
        $task = $this->task('pending_approval');
        $meeting = Meeting::create(['title' => 'جلسه', 'short_description' => 'خلاصه', 'status' => 'completed', 'created_by' => $this->sender->id, 'chairman_user_id' => $this->sender->id, 'secretary_user_id' => $this->leader->id]);
        $resolution = $meeting->resolutions()->create(['title' => 'مصوبه', 'resolution_type' => 'task', 'created_by' => $this->sender->id, 'task_id' => $task->id, 'materialized_at' => now()]);
        $cleared = $task->planningItems()->create(['title' => 'کار بدون واگذاری', 'weight' => 2, 'assignee_user_id' => $this->member->id]);
        $retained = $task->planningItems()->create(['title' => 'کار واگذارشده', 'weight' => 3, 'assignee_user_id' => $this->junior->id]);
        $url = '/api/v1/tasks/'.$task->id;

        Sanctum::actingAs($this->leader);
        $this->postJson($url.'/transfer', ['assignee_user_id' => $this->member->id])->assertOk();

        Sanctum::actingAs($this->member);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.task.meeting_source.resolution_id', $resolution->id)
            ->assertJsonPath('data.task.submission_type', 'assignment')
            ->assertJsonPath('data.task.allowed_actions.start', false)
            ->assertJsonCount(2, 'data.task.planning_items');
        $this->assertSame($task->id, $resolution->fresh()->task_id);
        $this->assertNull($cleared->fresh()->assignee_user_id);
        $this->assertSame($this->junior->id, $retained->fresh()->assignee_user_id);
        $this->postJson($url.'/acknowledge')->assertOk()->assertJsonPath('data.task.allowed_actions.start', true);
    }

    public function test_transfer_rejects_unrelated_inactive_targets_and_non_leaders_and_cannot_repeat(): void
    {
        $task = $this->task('pending_approval');
        $url = '/api/v1/tasks/'.$task->id.'/transfer';
        Sanctum::actingAs($this->outsider);
        $this->postJson($url, ['assignee_user_id' => $this->member->id])->assertForbidden();
        Sanctum::actingAs($this->leader);
        $this->postJson($url, ['assignee_user_id' => $this->outsider->id])->assertUnprocessable();
        $this->member->update(['is_active' => false]);
        $this->postJson($url, ['assignee_user_id' => $this->member->id])->assertUnprocessable();
        $this->member->update(['is_active' => true]);
        $this->postJson($url, ['assignee_user_id' => $this->member->id, 'team_member_ids' => []])->assertOk();
        $this->postJson($url, ['assignee_user_id' => $this->junior->id])->assertForbidden();
        $this->assertSame(1, $task->workflowHistory()->where('action', 'transferred')->count());
    }

    public function test_planning_assignment_and_team_edits_are_scoped_and_preserve_existing_acknowledgements(): void
    {
        $task = $this->task();
        $work = $task->planningItems()->create(['title' => 'کار', 'weight' => 1, 'assignee_user_id' => $this->junior->id]);
        $url = '/api/v1/tasks/'.$task->id;
        Sanctum::actingAs($this->member);
        $this->postJson($url.'/acknowledge')->assertOk();
        $viewed = $task->teamMembers()->whereKey($this->member->id)->first()->pivot->viewed_at;
        Sanctum::actingAs($this->leader);
        $this->patchJson($url.'/team', ['team_member_ids' => [$this->outsider->id]])->assertUnprocessable();
        $this->patchJson($url.'/team', ['team_member_ids' => [$this->member->id]])->assertUnprocessable();
        $this->junior->update(['is_active' => false]);
        $this->patchJson($url.'/planning', ['planning_items' => [['id' => $work->id, 'title' => 'کار', 'weight' => 1, 'assignee_user_id' => null]]])->assertOk();
        $this->patchJson($url.'/team', ['team_member_ids' => [$this->member->id]])->assertOk();
        $this->assertSame($viewed, $task->teamMembers()->whereKey($this->member->id)->first()->pivot->viewed_at);
        $this->patchJson($url.'/planning', ['planning_items' => [['id' => $work->id, 'title' => 'کار', 'weight' => 1, 'assignee_user_id' => $this->outsider->id]]])->assertUnprocessable();
        $this->assertNull($work->fresh()->assignee_user_id);
    }

    public function test_team_action_required_scope_tracks_viewing_and_rejects_progress_for_a_foreign_item(): void
    {
        $task = $this->task();
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/tasks?scope=action_required')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->postJson('/api/v1/tasks/'.$task->id.'/acknowledge')->assertOk();
        $this->getJson('/api/v1/tasks?scope=action_required')->assertOk()->assertJsonPath('data.meta.total', 0);
        $foreign = $this->task('in_progress')->planningItems()->create(['title' => 'کار نامرتبط', 'weight' => 1, 'assignee_user_id' => $this->member->id]);
        $this->patchJson('/api/v1/tasks/'.$task->id.'/planning-items/'.$foreign->id, ['progress_percentage' => 100])->assertNotFound();
        $this->assertSame(0, $foreign->fresh()->progress_percentage);
    }
}
