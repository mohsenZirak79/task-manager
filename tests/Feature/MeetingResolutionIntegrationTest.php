<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\Permission;
use App\Models\Task;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingResolutionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $secretary;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28T09:00:00Z'));
        Artisan::call('access:sync');
        $this->admin = User::factory()->admin()->create();
        $this->secretary = User::factory()->create();
        $role = AccessRole::create(['name' => 'منشی', 'slug' => 'test-secretary']);
        $role->permissions()->sync(Permission::whereIn('name', [
            Permissions::MEETINGS_VIEW, Permissions::MEETINGS_MANAGE_RESOLUTIONS, Permissions::TASKS_VIEW,
        ])->pluck('id'));
        $this->secretary->accessRoles()->sync([$role->id]);
        $this->meeting = Meeting::create([
            'title' => 'جلسه', 'status' => 'scheduled', 'created_by' => $this->admin->id,
            'secretary_user_id' => $this->secretary->id,
        ]);
        $this->meeting->attendees()->attach($this->secretary);
        Sanctum::actingAs($this->secretary);
    }

    private function task(User $owner): Task
    {
        $task = Task::create([
            'title' => 'تسک موجود', 'status' => 'ready_to_start', 'submission_type' => 'assignment',
            'created_by' => $owner->id, 'requester_id' => $owner->id,
        ]);
        $task->participantRecords()->create(['role' => 'assignee', 'user_id' => $owner->id]);

        return $task;
    }

    private function resolution(string $type = 'task')
    {
        return $this->meeting->resolutions()->create([
            'title' => 'مصوبه', 'description' => 'شرح کامل مصوبه', 'resolution_type' => $type,
            'created_by' => $this->admin->id,
        ]);
    }

    private function link($resolution, Task $task)
    {
        return $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$resolution->id}/link-task", ['task_id' => $task->id]);
    }

    public function test_secretary_with_permission_can_manage_and_link_existing_visible_task(): void
    {
        $resolution = $this->resolution();
        $task = $this->task($this->secretary);
        $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', true);
        $this->link($resolution, $task)->assertOk()->assertJsonPath('data.resolution.task.id', $task->id)
            ->assertJsonPath('data.resolution.description', 'شرح کامل مصوبه');
        $this->getJson("/api/v1/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.task.meeting_source.resolution_id', $resolution->id);
        $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.resolutions.0.task.id', $task->id);
        $this->assertDatabaseCount('tasks', 1);
        $this->link($resolution, $task)->assertConflict();
        $this->link($this->resolution(), $task)->assertConflict();
    }

    public function test_secretary_can_manage_without_global_permission_but_unrelated_user_cannot(): void
    {
        $resolution = $this->resolution();
        $task = $this->task($this->secretary);
        $role = $this->secretary->accessRoles()->firstOrFail();
        $role->permissions()->detach(Permission::where('name', Permissions::MEETINGS_MANAGE_RESOLUTIONS)->value('id'));
        $this->secretary->unsetRelation('accessRoles');
        $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', true);
        $this->link($resolution, $task)->assertOk();
        $outsider = User::factory()->create();
        $outsider->accessRoles()->sync([$role->id]);
        $role->permissions()->syncWithoutDetaching(Permission::where('name', Permissions::MEETINGS_MANAGE_RESOLUTIONS)->pluck('id'));
        Sanctum::actingAs($outsider);
        $this->link($resolution, $task)->assertForbidden();
    }

    public function test_link_requires_visible_task_and_all_assignees_to_be_meeting_attendees(): void
    {
        $resolution = $this->resolution();
        $task = $this->task($this->admin);
        $this->link($resolution, $task)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->link($resolution, $task)->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
        $this->meeting->attendees()->attach($this->admin);
        $this->link($resolution, $task)->assertOk();
    }

    public function test_link_rejects_report_resolutions_completed_meetings_and_wrong_parent(): void
    {
        $task = $this->task($this->secretary);
        $this->link($this->resolution('report'), $task)->assertConflict();
        $resolution = $this->resolution();
        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $this->meeting->update(['status' => $status]);
            $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()
                ->assertJsonPath('data.meeting.allowed_actions.manage_resolutions', false);
            $this->link($resolution, $task)->assertConflict();
        }
        $this->meeting->update(['status' => 'scheduled']);
        $other = Meeting::create(['title' => 'دیگر', 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        $resolution->update(['meeting_id' => $other->id]);
        $this->link($resolution, $task)->assertNotFound();
    }

    public function test_link_validates_task_id_and_keeps_report_resolution_content(): void
    {
        $resolution = $this->resolution();
        $url = "/api/v1/meetings/{$this->meeting->id}/resolutions/{$resolution->id}/link-task";
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['task_id' => 99999])->assertUnprocessable();
        $reportResolution = $this->resolution('report');
        $report = $reportResolution->report()->create([
            'title' => 'گزارش مصوبه', 'short_description' => 'خلاصه', 'description' => 'شرح گزارش',
            'status' => 'viewed', 'created_by' => $this->admin->id, 'recipient_user_id' => $this->secretary->id,
        ]);
        // A report resolution remains a report even after creating report-derived tasks.
        $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()
            ->assertJsonPath('data.meeting.resolutions.1.report.id', $report->id)
            ->assertJsonPath('data.meeting.resolutions.1.description', 'شرح کامل مصوبه')
            ->assertJsonPath('data.meeting.resolutions.1.resolution_type', 'report');
    }
}
