<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\MediaFile;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\OrgPosition;
use App\Models\Permission;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MeetingExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $chair;

    private User $member;

    private Meeting $meeting;

    private MeetingResolution $taskResolution;

    private MeetingResolution $reportResolution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01T08:00:00Z'));
        $this->chair = User::factory()->admin()->create();
        $this->member = User::factory()->create();
        $this->meeting = Meeting::create(['title' => 'جلسه اجرایی', 'short_description' => 'خلاصه', 'status' => 'scheduled', 'created_by' => $this->chair->id, 'chairman_user_id' => $this->chair->id, 'secretary_user_id' => $this->member->id]);
        $this->meeting->attendees()->attach([$this->chair->id, $this->member->id]);
        $this->taskResolution = $this->meeting->resolutions()->create(['title' => 'اجرای کار', 'resolution_type' => 'task', 'created_by' => $this->chair->id, 'sort_order' => 0]);
        $this->reportResolution = $this->meeting->resolutions()->create(['title' => 'گزارش کار', 'resolution_type' => 'report', 'created_by' => $this->chair->id, 'sort_order' => 1]);
        Sanctum::actingAs($this->chair);
    }

    private function taskPayload(array $extra = []): array
    {
        return ['title' => 'تسک', 'short_description' => 'خلاصه تسک', 'duration_minutes' => 720, 'due_date' => '2026-11-10', 'assignee_ids' => [$this->chair->id], 'follower_ids' => [$this->member->id], 'supervisor_ids' => [$this->member->id], ...$extra];
    }

    private function register(): void
    {
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}/create-task", $this->taskPayload())
            ->assertOk()->assertJsonPath('data.resolution.execution_status', 'registered')->assertJsonMissingPath('data.task');
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->reportResolution->id}/report", ['title' => 'گزارش', 'short_description' => 'خلاصه گزارش', 'description' => 'شرح گزارش', 'recipient_user_id' => $this->member->id, 'cc_user_ids' => [$this->chair->id]])
            ->assertOk()->assertJsonPath('data.resolution.execution_status', 'registered')->assertJsonMissingPath('data.report');
    }

    private function version(): string
    {
        return $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()->json('data.meeting.signature_content_version');
    }

    private function sign(User $user, string $version)
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/meetings/{$this->meeting->id}/sign", ['content_version' => $version]);
    }

    public function test_registering_creates_no_business_records_and_completion_requires_every_form(): void
    {
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertUnprocessable()->assertJsonValidationErrors('resolutions');
        $this->register();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reports', 0);
        $this->sign($this->chair, $this->version())->assertForbidden();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_last_signature_atomically_materializes_once_without_invalidating_signatures(): void
    {
        $this->register();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $version = $this->version();
        $this->sign($this->chair, $version)->assertOk();
        $this->assertDatabaseCount('tasks', 0);
        $this->sign($this->member, $version)->assertOk()->assertJsonPath('data.meeting.signature_content_version', $version)->assertJsonPath('data.meeting.execution_readiness.all_signed', true);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('tasks', ['duration_minutes' => 720, 'status' => 'pending_approval', 'created_by' => $this->chair->id]);
        $this->assertDatabaseHas('reports', ['status' => 'sent', 'recipient_user_id' => $this->member->id]);
        $this->assertSame($version, $this->version());
        $this->sign($this->member, $version)->assertOk();
        $this->sign($this->chair, $version)->assertOk();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('meeting_signatures', 2);
        $this->assertNotNull($this->taskResolution->fresh()->materialized_at);
    }

    public function test_outsiders_cannot_be_followers_supervisors_or_report_recipients_even_for_admin(): void
    {
        $outsider = User::factory()->create();
        foreach (['assignee_ids', 'follower_ids', 'supervisor_ids'] as $field) {
            $payload = $this->taskPayload([$field => [$outsider->id], 'tags' => ['جلسه']]);
            $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}/create-task", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->reportResolution->id}/report", ['title' => 'گزارش', 'short_description' => 'خلاصه', 'description' => 'شرح', 'recipient_user_id' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('recipient_user_id');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_plan_retries_are_idempotent_and_plans_are_frozen_after_completion(): void
    {
        $this->register();
        $version = $this->version();
        $this->register();
        $this->assertSame($version, $this->version());
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}/create-task", $this->taskPayload())->assertConflict();
    }

    public function test_reordering_is_complete_scoped_and_locked_after_completion(): void
    {
        $url = "/api/v1/meetings/{$this->meeting->id}/resolutions/order";
        $this->putJson($url, ['resolution_ids' => [$this->reportResolution->id, $this->taskResolution->id]])->assertOk()->assertJsonPath('data.meeting.resolutions.0.id', $this->reportResolution->id);
        $this->putJson($url, ['resolution_ids' => [$this->taskResolution->id]])->assertUnprocessable();
        $this->putJson($url, ['resolution_ids' => [$this->taskResolution->id, $this->taskResolution->id]])->assertUnprocessable();
        $this->register();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $this->putJson($url, ['resolution_ids' => [$this->taskResolution->id, $this->reportResolution->id]])->assertConflict();
    }

    public function test_existing_task_linking_is_disabled_and_foreign_meeting_cannot_be_changed(): void
    {
        $task = Task::create(['title' => 'موجود', 'submission_type' => 'assignment', 'status' => 'draft', 'created_by' => $this->chair->id, 'requester_id' => $this->chair->id]);
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}/link-task", ['task_id' => $task->id])->assertConflict();
        $this->patchJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}", ['task_id' => $task->id])->assertConflict();
        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/meetings/{$this->meeting->id}/resolutions/order", ['resolution_ids' => [$this->taskResolution->id, $this->reportResolution->id]])->assertForbidden();
    }

    public function test_failed_last_signature_rolls_back_all_outputs_and_can_be_retried(): void
    {
        $this->register();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $version = $this->version();
        $this->sign($this->chair, $version)->assertOk();
        $roles = $this->chair->accessRoles()->pluck('access_roles.id');
        $this->chair->orgPositions()->attach(OrgPosition::create(['title' => 'مجری جلسه']));
        $restricted = AccessRole::create(['name' => 'Task only executor', 'slug' => 'test-execution-no-reports']);
        $restricted->permissions()->sync(Permission::where('name', '!=', 'reports:manage')->pluck('id'));
        $this->chair->accessRoles()->sync([$restricted->id]);
        $this->sign($this->member, $version)->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('meeting_signatures', 1);
        $this->assertNull($this->taskResolution->fresh()->materialized_at);
        $this->chair->accessRoles()->sync($roles);
        $this->sign($this->member, $version)->assertOk();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_plan_changes_require_new_version_and_preserve_attachments(): void
    {
        $this->register();
        $old = $this->version();
        $file = MediaFile::create(['disk' => 'public', 'path' => 'attachments/minutes.pdf', 'original_name' => 'minutes.pdf', 'mime_type' => 'application/pdf', 'category' => 'attachment', 'size' => 123, 'uploaded_by' => $this->chair->id]);
        $payload = $this->taskPayload(['title' => 'تسک اصلاح‌شده', 'attachment_file_ids' => [$file->id], 'planning_items' => [['title' => 'مرحله اول', 'weight' => 100, 'progress_percentage' => 0, 'sort_order' => 0]]]);
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/resolutions/{$this->taskResolution->id}/create-task", $payload)->assertOk();
        $new = $this->version();
        $this->assertNotSame($old, $new);
        $this->getJson("/api/v1/meetings/{$this->meeting->id}")->assertOk()->assertJsonPath('data.meeting.execution_files.0.id', $file->id);
        $this->deleteJson("/api/v1/uploads/files/{$file->id}")->assertConflict();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $this->sign($this->chair, $old)->assertConflict();
        $this->sign($this->chair, $new)->assertOk();
        $this->sign($this->member, $new)->assertOk();
        $task = Task::firstOrFail();
        $this->assertSame('تسک اصلاح‌شده', $task->title);
        $this->assertSame([$file->id], $task->attachments()->pluck('media_files.id')->all());
        $this->assertSame(1, $task->planningItems()->count());
    }

    public function test_list_and_action_query_show_only_current_users_pending_signatures(): void
    {
        $this->register();
        $this->postJson("/api/v1/meetings/{$this->meeting->id}/complete")->assertOk();
        $version = $this->version();
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.items.0.requires_action', true);
        $this->assertSame(1, app(MeetingService::class)->actionRequiredQuery($this->member)->count());
        $this->sign($this->member, $version)->assertOk();
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.items.0.requires_action', false);
        $this->assertSame(0, app(MeetingService::class)->actionRequiredQuery(auth()->user())->count());
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->assertSame(0, app(MeetingService::class)->actionRequiredQuery(auth()->user())->count());
    }

    public function test_stale_resolution_cannot_change_the_type_of_a_registered_plan(): void
    {
        $stale = $this->taskResolution->fresh();
        $this->register();
        try {
            app(MeetingService::class)->updateResolution($this->meeting, $stale, ['resolution_type' => 'report'], $this->chair);
            $this->fail('A registered plan must retain its type even if route binding was stale.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('task', $stale->fresh()->resolution_type->value);
    }

    public function test_rollback_refuses_to_destroy_registered_forms(): void
    {
        $this->register();
        $migration = require database_path('migrations/2026_11_01_000000_add_resolution_execution_plans.php');
        try {
            $migration->down();
            $this->fail('Rollback must not discard saved form data.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Cannot roll back while resolution execution plans exist.', $error->getMessage());
        }
        $this->assertNotNull($this->taskResolution->fresh()->execution_plan);
        $this->assertNotNull($this->reportResolution->fresh()->execution_plan);
    }
}
