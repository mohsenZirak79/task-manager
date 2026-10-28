<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\MediaFile;
use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingSignatureContent;
use App\Services\ReportTaskService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $recipient;

    private User $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28T09:00:00Z'));
        Artisan::call('access:sync');
        $this->creator = User::factory()->admin()->create();
        $this->recipient = User::factory()->create();
        $this->cc = User::factory()->create();
        $root = OrgPosition::create(['title' => 'شرکت']);
        foreach ([$this->recipient, $this->cc] as $user) {
            OrgPosition::create(['title' => 'کارشناس', 'parent_id' => $root->id])->users()->attach($user);
        }
        Sanctum::actingAs($this->recipient);
    }

    private function report(array $attributes = []): Report
    {
        $report = Report::create([
            'title' => 'گزارش مبدأ', 'short_description' => str_repeat('الف', 120),
            'description' => 'شرح گزارش', 'created_by' => $this->creator->id,
            'recipient_user_id' => $this->recipient->id, 'status' => 'viewed',
            'sent_at' => now()->subHour(), 'viewed_at' => now(), ...$attributes,
        ]);
        $report->ccUsers()->attach($this->cc);

        return $report;
    }

    private function payload(array $extra = []): array
    {
        return ['submission_key' => (string) Str::uuid(), 'submission_type' => 'assignment', ...$extra];
    }

    private function create(Report $report, array $payload)
    {
        return $this->postJson("/api/v1/reports/{$report->id}/create-task", $payload);
    }

    private function permissions(User $user, array $permissions): void
    {
        $role = AccessRole::create(['name' => 'Test', 'slug' => 'test-'.Str::uuid(), 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        $user->accessRoles()->sync([$role->id]);
        $user->unsetRelation('accessRoles');
        Sanctum::actingAs($user);
    }

    public function test_recipient_can_create_multiple_tasks_with_suggestions_and_custom_fields(): void
    {
        $report = $this->report();
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()
            ->assertJsonPath('data.report.allowed_actions.create_task', true)
            ->assertJsonPath('data.report.tasks_count', 0)
            ->assertJsonPath('data.report.task_suggestions.short_description', mb_substr($report->short_description, 0, 100));
        $first = $this->create($report, $this->payload())->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.task.title', $report->title)
            ->assertJsonPath('data.task.request_description', $report->description)
            ->assertJsonPath('data.task.status', 'draft')
            ->assertJsonPath('data.task.assignees.0.id', $this->recipient->id)
            ->assertJsonPath('data.task.report_source.id', $report->id)->json('data.task.id');
        $this->create($report, $this->payload(['title' => 'سفارشی', 'short_description' => 'خلاصه دلخواه']))
            ->assertCreated()->assertJsonPath('data.task.title', 'سفارشی');
        $this->getJson("/api/v1/tasks/$first")->assertOk()->assertJsonPath('data.task.report_source.title', $report->title);
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()->assertJsonPath('data.report.tasks_count', 2);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_retries_are_idempotent_key_order_independent_and_conflicting_payload_is_rejected(): void
    {
        $report = $this->report();
        $payload = $this->payload(['title' => 'تسک اول']);
        $id = $this->create($report, $payload)->assertCreated()->json('data.task.id');
        $report->update(['title' => 'عنوان جدید مبدأ']);
        $this->create($report, array_reverse($payload, true))->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.task.id', $id)
            ->assertJsonPath('data.task.title', 'تسک اول');
        $this->create($report, [...$payload, 'title' => 'متفاوت'])->assertConflict();
        $this->assertDatabaseCount('tasks', 1);
        $this->create($this->report(), $payload)->assertCreated();
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_only_primary_recipient_of_viewed_report_can_create_even_for_super_admin(): void
    {
        $report = $this->report();
        foreach ([$this->cc, $this->creator, User::factory()->create()] as $actor) {
            Sanctum::actingAs($actor);
            $this->create($report, $this->payload())->assertForbidden();
        }
        Sanctum::actingAs($this->recipient);
        foreach (['draft', 'sent', 'inactive'] as $status) {
            $report->update(['status' => $status]);
            $this->create($report, $this->payload())->assertForbidden();
            $this->getJson("/api/v1/reports/{$report->id}")->assertOk()->assertJsonPath('data.report.allowed_actions.create_task', false);
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_module_permissions_organization_and_active_user_are_enforced(): void
    {
        $report = $this->report();
        foreach ([[Permissions::REPORTS_VIEW], [Permissions::TASKS_CREATE]] as $permissions) {
            $this->permissions($this->recipient, $permissions);
            $this->create($report, $this->payload())->assertForbidden();
        }
        $this->permissions($this->recipient, [Permissions::REPORTS_VIEW, Permissions::TASKS_CREATE]);
        $this->recipient->orgPositions()->detach();
        $this->create($report, $this->payload())->assertForbidden();
        $this->recipient->update(['is_active' => false]);
        $this->create($report, $this->payload())->assertForbidden();
    }

    public function test_guest_missing_report_and_validation_errors_do_not_create_tasks(): void
    {
        $report = $this->report();
        $this->create($report, [])->assertUnprocessable()->assertJsonValidationErrors(['submission_key', 'submission_type']);
        foreach ([['submission_key' => 'bad'], ['source_report_id' => $report->id], ['report_request_hash' => 'fake'],
            ['assignee_ids' => [$this->cc->id]], ['due_date' => '2026-10-27'], ['planning_items' => [['weight' => -1]]]] as $invalid) {
            $this->create($report, $this->payload($invalid))->assertUnprocessable();
        }
        $this->create($report, $this->payload(['submit' => true]))->assertUnprocessable();
        $this->postJson('/api/v1/reports/99999/create-task', $this->payload())->assertNotFound();
        auth()->forgetGuards();
        $this->create($report, $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_full_task_payload_keeps_planning_tags_attachments_resources_and_submission(): void
    {
        $report = $this->report();
        $file = MediaFile::create([
            'disk' => 'public', 'path' => 'attachments/report-test.pdf', 'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf', 'category' => 'attachment', 'size' => 123, 'uploaded_by' => $this->recipient->id,
        ]);
        $response = $this->create($report, $this->payload([
            'title' => 'کار', 'short_description' => 'خلاصه', 'submit' => true,
            'duration_minutes' => 60, 'due_date' => '2026-11-01', 'tags' => ['پروژه'],
            'attachment_file_ids' => [$file->id],
            'planning_items' => [['title' => 'گام اول', 'weight' => 2, 'progress_percentage' => 50]],
            'financial_resources' => 'بودجه', 'financial_estimated_cost' => 1200,
            'equipment_resources' => 'ابزار', 'equipment_estimated_cost' => 400,
        ]))->assertCreated()->assertJsonPath('data.task.status', 'pending_approval')
            ->assertJsonPath('data.task.progress_percentage', 0)
            ->assertJsonPath('data.task.tags.0.title', 'پروژه')
            ->assertJsonPath('data.task.attachments.0.id', $file->id)
            ->assertJsonPath('data.task.financial_estimated_cost', '1200.00')
            ->assertJsonCount(1, 'data.task.planning_items');
        $this->assertDatabaseHas('task_workflow_histories', ['task_id' => $response->json('data.task.id'), 'action' => 'submitted']);
    }

    public function test_request_task_uses_existing_assignment_rules_and_workflow(): void
    {
        $this->create($this->report(), $this->payload([
            'submission_type' => 'request', 'title' => 'درخواست', 'short_description' => 'خلاصه',
            'submit' => true, 'duration_minutes' => 30, 'due_date' => '2026-11-01',
            'assignee_ids' => [$this->cc->id], 'follower_ids' => [$this->recipient->id],
            'supervisor_ids' => [$this->recipient->id], 'tags' => ['کار'],
        ]))->assertCreated()->assertJsonPath('data.task.status', 'pending_approval')
            ->assertJsonPath('data.task.submission_type', 'request')
            ->assertJsonPath('data.task.assignees.0.id', $this->cc->id);
    }

    public function test_failed_attachment_authorization_rolls_back_task_and_retry_can_succeed(): void
    {
        $report = $this->report();
        $file = MediaFile::create([
            'disk' => 'public', 'path' => 'attachments/other.pdf', 'original_name' => 'other.pdf',
            'mime_type' => 'application/pdf', 'category' => 'attachment', 'size' => 123, 'uploaded_by' => $this->creator->id,
        ]);
        $payload = $this->payload(['attachment_file_ids' => [$file->id]]);
        $this->create($report, $payload)->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
        $file->update(['uploaded_by' => $this->recipient->id]);
        $this->create($report, $payload)->assertCreated();
    }

    public function test_resolution_report_creates_tasks_without_replacing_the_resolution_or_invalidating_signatures(): void
    {
        $meeting = Meeting::create(['title' => 'جلسه', 'status' => 'completed', 'created_by' => $this->creator->id]);
        $resolution = $meeting->resolutions()->create(['title' => 'مصوبه گزارش', 'resolution_type' => 'report', 'created_by' => $this->creator->id]);
        $report = $this->report(['meeting_resolution_id' => $resolution->id]);
        $content = app(MeetingSignatureContent::class);
        $before = $content->version($content->snapshot($meeting));
        $this->create($report, $this->payload())->assertCreated()->assertJsonPath('data.task.meeting_source', null);
        $this->assertNull($resolution->fresh()->task_id);
        $this->assertSame($before, $content->version($content->snapshot($meeting->fresh())));
    }

    public function test_counts_and_paginated_relationship_include_only_visible_tasks_and_have_stable_order(): void
    {
        $report = $this->report();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->create($report, $this->payload())->assertCreated()->json('data.task.id');
        }
        $hidden = Task::create(['title' => 'محرمانه', 'status' => 'draft', 'submission_type' => 'assignment',
            'created_by' => $this->creator->id, 'requester_id' => $this->creator->id]);
        $hidden->forceFill(['source_report_id' => $report->id])->save();
        $this->getJson("/api/v1/reports/{$report->id}/tasks?per_page=2")->assertOk()
            ->assertJsonPath('data.meta.total', 3)->assertJsonPath('data.items.0.id', $ids[2])
            ->assertJsonCount(2, 'data.items');
        $this->getJson("/api/v1/reports/{$report->id}/tasks?per_page=2&page=2")->assertOk()
            ->assertJsonPath('data.items.0.id', $ids[0])->assertJsonCount(1, 'data.items');
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()->assertJsonPath('data.report.tasks_count', 3);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.items.0.tasks_count', 3);
        $this->getJson("/api/v1/reports/{$report->id}/tasks?per_page=101&page=0")->assertUnprocessable();
        Sanctum::actingAs($this->cc);
        $this->getJson("/api/v1/reports/{$report->id}/tasks")->assertOk()->assertJsonPath('data.meta.total', 0);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/reports/{$report->id}/tasks")->assertForbidden();
    }

    public function test_report_counters_do_not_leak_tasks_without_module_permission(): void
    {
        $report = $this->report();
        $this->create($report, $this->payload())->assertCreated();
        $this->permissions($this->recipient, [Permissions::REPORTS_VIEW, Permissions::TASKS_CREATE]);
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()
            ->assertJsonPath('data.report.tasks_count', null)->assertJsonPath('data.report.tasks_url', null);
        $this->getJson("/api/v1/reports/{$report->id}/tasks")->assertForbidden();
    }

    public function test_source_metadata_requires_report_access_and_unrelated_tasks_remain_compatible(): void
    {
        $report = $this->report();
        $id = $this->create($report, $this->payload())->assertCreated()->json('data.task.id');
        $this->permissions($this->recipient, [Permissions::TASKS_VIEW]);
        $this->getJson("/api/v1/tasks/$id")->assertOk()->assertJsonPath('data.task.report_source', null);
        Sanctum::actingAs($this->creator);
        $id = $this->postJson('/api/v1/tasks', ['submission_type' => 'assignment', 'title' => 'مستقل'])
            ->assertCreated()->assertJsonPath('data.task.report_source', null)->json('data.task.id');
        $this->getJson("/api/v1/tasks/$id")->assertOk()->assertJsonPath('data.task.report_source', null);
    }

    public function test_linked_report_cannot_be_deleted_but_deactivation_preserves_source(): void
    {
        $report = $this->report();
        $id = $this->create($report, $this->payload())->assertCreated()->json('data.task.id');
        Sanctum::actingAs($this->creator);
        $this->getJson("/api/v1/reports/{$report->id}")->assertOk()->assertJsonPath('data.report.allowed_actions.delete', false);
        $this->deleteJson("/api/v1/reports/{$report->id}")->assertConflict();
        $this->postJson("/api/v1/reports/{$report->id}/deactivate")->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'source_report_id' => $report->id]);
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'inactive']);
    }

    public function test_service_rechecks_locked_report_instead_of_stale_authorized_model(): void
    {
        $report = $this->report();
        DB::table('reports')->where('id', $report->id)->update(['recipient_user_id' => $this->cc->id]);
        $this->expectException(AuthorizationException::class);
        app(ReportTaskService::class)->create($report, $this->payload(), $this->recipient);
    }

    public function test_database_constraint_prevents_duplicate_creation_keys(): void
    {
        $report = $this->report();
        $payload = $this->payload();
        $id = $this->create($report, $payload)->assertCreated()->json('data.task.id');
        $task = Task::findOrFail($id);
        $duplicate = $task->replicate();
        $duplicate->forceFill(['report_submission_key' => $payload['submission_key']]);
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_database_restricts_source_report_deletion_even_without_model_events(): void
    {
        $report = $this->report();
        $this->create($report, $this->payload())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('reports')->where('id', $report->id)->delete();
    }

    public function test_task_update_cannot_reassign_its_report_source(): void
    {
        $report = $this->report();
        $other = $this->report();
        $id = $this->create($report, $this->payload())->assertCreated()->json('data.task.id');
        $this->patchJson("/api/v1/tasks/$id", ['title' => 'ویرایش', 'source_report_id' => $other->id])
            ->assertOk()->assertJsonPath('data.task.report_source.id', $report->id);
        $this->assertDatabaseHas('tasks', ['id' => $id, 'source_report_id' => $report->id]);
    }

    public function test_related_task_list_does_not_add_queries_per_task(): void
    {
        $report = $this->report();
        $this->create($report, $this->payload())->assertCreated();
        $queries = function () use ($report): int {
            Sanctum::actingAs($this->recipient->fresh());
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson("/api/v1/reports/{$report->id}/tasks")->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $queries();
        for ($i = 0; $i < 4; $i++) {
            $this->create($report, $this->payload())->assertCreated();
        }
        $this->assertSame($one, $queries());
    }

    public function test_report_list_does_not_add_count_or_permission_queries_per_report(): void
    {
        $this->report();
        // Keep last-activity middleware from issuing its one-time timestamp write.
        $this->recipient->forceFill(['last_activity_at' => now()])->save();
        $queries = function (): int {
            Sanctum::actingAs($this->recipient->fresh());
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/reports')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $queries();
        for ($i = 0; $i < 4; $i++) {
            $this->report();
        }
        $this->assertSame($one, $queries());
    }
}
