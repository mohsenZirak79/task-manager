<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\DashboardProjectStatistics;
use App\Services\DashboardReadSnapshot;
use App\Services\DashboardScheduleStatistics;
use App\Services\TaskVisibilityService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as refreshWithTransaction; }

    public function refreshDatabase(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Dashboard must own its read-only transaction; fixtures must be committed.
            $this->artisan('migrate:fresh', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;

            return;
        }
        $this->refreshWithTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'Asia/Tehran'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function task(User $owner, array $attributes = []): Task
    {
        return Task::create(array_merge([
            'title' => 'Task', 'short_description' => 'Summary', 'created_by' => $owner->id,
            'requester_id' => $owner->id, 'submission_type' => 'assignment', 'status' => 'in_progress',
            'due_date' => '2026-10-20',
        ], $attributes));
    }

    private function report(User $owner, array $attributes = []): Report
    {
        return Report::create(array_merge([
            'title' => 'Report', 'short_description' => 'Summary', 'description' => 'Details',
            'created_by' => $owner->id, 'recipient_user_id' => $owner->id, 'status' => 'sent',
        ], $attributes));
    }

    private function meeting(User $owner, array $attributes = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'Meeting', 'created_by' => $owner->id,
            'chairman_user_id' => $owner->id, 'secretary_user_id' => $owner->id,
            'meeting_date' => '2026-10-20', 'start_time' => '10:00', 'status' => 'draft',
        ], $attributes));
    }

    public function test_authentication_and_empty_data_and_pagination_validation(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->admin()->create());
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.timezone', 'Asia/Tehran')
            ->assertJsonPath('data.generated_at', '2026-10-14T12:00:00+03:30')
            ->assertJsonPath('data.task_statuses.total', 0)
            ->assertJsonPath('data.task_schedule.total', 0)
            ->assertJsonPath('data.projects.meta.total', 0)
            ->assertJsonPath('data.reports.classified_total', 0)
            ->assertJsonCount(10, 'data.task_statuses.items')->json('data');
        foreach (['task_statuses', 'task_schedule', 'reports'] as $section) {
            foreach ($data[$section]['items'] as $item) {
                $this->assertSame(0, $item['count']);
                $this->assertEquals(0, $item['percentage']);
            }
        }
        foreach ($data['cards'] as $card) {
            $this->assertSame(['available' => true, 'count' => 0], $card);
        }
        $this->getJson('/api/v1/dashboard?projects_page=0&projects_per_page=101')->assertUnprocessable();
    }

    public function test_all_enum_statuses_are_distinct_and_requests_have_separate_card(): void
    {
        $actor = User::factory()->create();
        foreach (TaskStatus::cases() as $status) {
            $this->task($actor, ['status' => $status->value]);
            $this->task($actor, ['status' => $status->value, 'submission_type' => 'request']);
        }
        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_statuses.total', 10)
            ->assertJsonPath('data.task_schedule.examined_total', 10)
            ->assertJsonPath('data.cards.active_tasks.count', 3)
            ->assertJsonPath('data.cards.active_requests.count', 5);
        $items = $response->json('data.task_statuses.items');
        $this->assertSame(array_column(TaskStatus::cases(), 'value'), array_column($items, 'key'));
        foreach ($items as $item) {
            $this->assertSame(1, $item['count']);
            $this->assertEquals(10, $item['percentage']);
            $this->assertNotEmpty($item['label']);
        }
    }

    public static function scheduleCases(): array
    {
        return [
            'more than 48 hours' => ['2026-10-12 23:59:58.999999', '2026-10-14', 'in_progress', null, 'on_schedule'],
            'exactly 48 hours' => ['2026-10-12 23:59:59.999999', '2026-10-14', 'in_progress', null, 'critical'],
            'less than 48 hours' => ['2026-10-13 00:00:00', '2026-10-14', 'in_progress', null, 'critical'],
            'at Tehran deadline' => ['2026-10-14 23:59:59.999999', '2026-10-14', 'in_progress', null, 'critical'],
            'past Tehran deadline' => ['2026-10-15 00:00:00', '2026-10-14', 'in_progress', null, 'overdue'],
            'UTC day is not Tehran day' => ['2026-10-15 00:01:00', '2026-10-14', 'in_progress', null, 'overdue'],
            'completed on time despite current overdue' => ['2026-10-20 12:00:00', '2026-10-14', 'completed', '2026-10-14 20:29:59', 'on_schedule'],
            'completed late despite future due date relative to now' => ['2026-10-10 12:00:00', '2026-10-14', 'completed', '2026-10-14 20:30:00', 'overdue'],
            'completed before deadline' => ['2026-10-20 12:00:00', '2026-10-14', 'completed', '2026-10-13 10:00:00', 'on_schedule'],
            'missing due date' => ['2026-10-14 12:00:00', null, 'in_progress', null, 'missing_due_date'],
            'missing completion timestamp' => ['2026-10-14 12:00:00', '2026-10-14', 'completed', null, 'missing_completed_at'],
            'both missing use due date reason once' => ['2026-10-14 12:00:00', null, 'completed', null, 'missing_due_date'],
            'draft excluded first' => ['2026-10-14 12:00:00', null, 'draft', null, 'excluded_status'],
            'rejected excluded' => ['2026-10-14 12:00:00', '2026-10-14', 'rejected', null, 'excluded_status'],
            'closed excluded' => ['2026-10-14 12:00:00', '2026-10-14', 'closed', null, 'excluded_status'],
            'not completed excluded' => ['2026-10-14 12:00:00', '2026-10-14', 'not_completed', null, 'excluded_status'],
            'pending approval is open' => ['2026-10-14 12:00:00', '2026-10-14', 'pending_approval', null, 'critical'],
            'revision requested is open' => ['2026-10-14 12:00:00', '2026-10-14', 'revision_requested', null, 'critical'],
            'ready to start is open' => ['2026-10-14 12:00:00', '2026-10-14', 'ready_to_start', null, 'critical'],
            'pending completion is open' => ['2026-10-14 12:00:00', '2026-10-14', 'pending_completion_approval', null, 'critical'],
            '48 elapsed hours across spring DST' => ['2021-03-21 22:59:59.999999', '2021-03-23', 'in_progress', null, 'critical'],
            'over 48 elapsed hours across spring DST' => ['2021-03-21 22:59:58.999999', '2021-03-23', 'in_progress', null, 'on_schedule'],
            'historical Tehran daylight saving' => ['2026-10-14 12:00:00', '2020-07-01', 'completed', '2020-07-01 19:30:00', 'overdue'],
        ];
    }

    #[DataProvider('scheduleCases')]
    public function test_schedule_boundaries(string $now, ?string $due, string $status, ?string $completed, string $expected): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'Asia/Tehran'));
        $actor = User::factory()->create();
        $task = $this->task($actor, ['due_date' => $due, 'status' => $status]);
        // Preserve microseconds to exercise the mathematical end-of-day boundary.
        DB::table('tasks')->where('id', $task->id)->update(['completed_at' => $completed]);
        Sanctum::actingAs($actor);
        $chart = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.task_schedule');
        $this->assertSame(1, $chart['examined_total']);
        $this->assertSame($chart['examined_total'], $chart['total'] + $chart['excluded_total']);
        $this->assertSame($chart['total'], array_sum(array_column($chart['items'], 'count')));
        foreach ($chart['items'] as $item) {
            $this->assertSame($item['key'] === $expected ? 1 : 0, $item['count']);
            $this->assertEquals($item['key'] === $expected ? 100 : 0, $item['percentage']);
        }
        foreach ($chart['excluded_counts'] as $reason => $count) {
            $this->assertSame($reason === $expected ? 1 : 0, $count);
        }
        $this->assertSame($status, $task->fresh()->status->value);
    }

    public function test_projects_use_unique_visible_assignments_and_eligible_progress_with_pagination(): void
    {
        // Production currently forbids null progress. Exercise compatibility with legacy nullable data.
        Schema::table('tasks', fn (Blueprint $table) => $table->unsignedTinyInteger('progress_percentage')->nullable()->default(0)->change());
        [$actor, $other] = User::factory()->count(2)->create()->all();
        $a = Tag::create(['title' => 'A']);
        $b = Tag::create(['title' => 'B']);
        $hiddenTag = Tag::create(['title' => 'Private']);
        $task = $this->task($actor, ['progress_percentage' => 100, 'status' => 'completed']);
        $task->tags()->attach([$a->id, $b->id]);
        $task->participantRecords()->createMany([
            ['user_id' => $actor->id, 'role' => 'assignee'],
            ['user_id' => $actor->id, 'role' => 'follower'],
        ]);
        foreach ([['in_progress', null], ['ready_to_start', 1], ['draft', 90], ['rejected', 90], ['closed', 90], ['not_completed', 90]] as [$status, $progress]) {
            $this->task($actor, ['status' => $status, 'progress_percentage' => $progress])->tags()->attach($a);
        }
        $this->task($actor, ['submission_type' => 'request', 'progress_percentage' => 100])->tags()->attach($a);
        $this->task($other, ['progress_percentage' => 100])->tags()->attach([$a->id, $hiddenTag->id]);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard?projects_per_page=1')->assertOk()
            ->assertJsonCount(1, 'data.projects.items')
            ->assertJsonPath('data.projects.meta.total', 2)
            ->assertJsonPath('data.projects.meta.last_page', 2)
            ->assertJsonPath('data.projects.items.0.tag_id', $a->id)
            ->assertJsonPath('data.projects.items.0.tasks_count', 7)
            ->assertJsonPath('data.projects.items.0.completed_count', 1)
            ->assertJsonPath('data.projects.items.0.progress_percentage', 33.67);
        $this->getJson('/api/v1/dashboard?projects_per_page=1&projects_page=2')->assertOk()
            ->assertJsonPath('data.projects.items.0.tag_id', $b->id)
            ->assertJsonPath('data.projects.items.0.tasks_count', 1)
            ->assertJsonPath('data.projects.items.0.progress_percentage', 100);
    }

    public function test_task_visibility_matches_policy_including_providers_and_participants(): void
    {
        [$actor, $other] = User::factory()->count(2)->create()->all();
        $tasks = [$this->task($actor), $this->task($other),
            $this->task($other, ['requester_id' => $actor->id]),
            $this->task($other, ['financial_provider_user_id' => $actor->id]),
            $this->task($other, ['equipment_provider_user_id' => $actor->id])];
        foreach (['assignee', 'follower', 'supervisor'] as $role) {
            $task = $this->task($other, ['status' => 'draft']);
            $task->participantRecords()->create(['user_id' => $actor->id, 'role' => $role]);
            $tasks[] = $task;
        }
        $allowed = array_filter($tasks, fn ($task) => Gate::forUser($actor)->allows('view', $task));
        $ids = app(TaskVisibilityService::class)->visibleQuery($actor)->pluck('id')->all();
        $this->assertEqualsCanonicalizing(array_values(array_map(fn ($task) => $task->id, $allowed)), $ids);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.task_statuses.total', 7)
            ->assertJsonPath('data.cards.active_tasks.count', 4);
        $actor->accessRoles()->attach(AccessRole::create(['slug' => 'dashboard-wide-test', 'name' => 'Test'])->id);
        $role = AccessRole::where('slug', 'dashboard-wide-test')->firstOrFail();
        $role->permissions()->attach(Permission::where('name', Permissions::TASKS_VIEW_ALL)->firstOrFail());
        $actor->unsetRelation('accessRoles');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.task_statuses.total', 8);
    }

    public function test_report_and_meeting_visibility_match_policies_and_report_categories_are_independent(): void
    {
        [$actor, $other] = User::factory()->count(2)->create()->all();
        $meetings = [$this->meeting($actor), $this->meeting($other),
            $this->meeting($other, ['chairman_user_id' => $actor->id]),
            $this->meeting($other, ['secretary_user_id' => $actor->id]), $this->meeting($other)];
        $meetings[4]->attendees()->attach($actor);
        $reports = [$this->report($actor, ['action_type' => 'needs_action']),
            $this->report($other, ['recipient_user_id' => $actor->id, 'action_type' => 'needs_review', 'status' => 'viewed']),
            $this->report($other), $this->report($other, ['action_type' => 'needs_instruction'])];
        $reports[3]->ccUsers()->attach($actor);
        foreach ([$meetings[0], $meetings[2], $meetings[3], $meetings[4]] as $meeting) {
            $resolution = MeetingResolution::create(['meeting_id' => $meeting->id, 'title' => 'Resolution',
                'resolution_type' => 'report', 'created_by' => $other->id, 'sort_order' => 1]);
            $reports[] = $this->report($other, ['meeting_resolution_id' => $resolution->id]);
        }
        $this->report($actor, ['status' => 'draft', 'action_type' => 'needs_action']);
        $this->report($actor, ['status' => 'inactive', 'action_type' => 'needs_review']);
        $expectedReports = count(array_filter($reports, fn ($report) => Gate::forUser($actor)->allows('view', $report)));
        $expectedMeetings = count(array_filter($meetings, fn ($meeting) => Gate::forUser($actor)->allows('view', $meeting)));
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.cards.registered_reports.count', $expectedReports)
            ->assertJsonPath('data.cards.meetings.count', $expectedMeetings)
            ->assertJsonPath('data.reports.total', 7)
            ->assertJsonPath('data.reports.classified_total', 3)
            ->assertJsonPath('data.reports.unclassified_count', 4)
            ->assertJsonPath('data.reports.items.0.count', 1)
            ->assertJsonPath('data.reports.items.1.percentage', 33.33);
    }

    public function test_modules_without_view_permission_are_explicitly_unavailable_even_for_owned_data(): void
    {
        $actor = User::factory()->create();
        $this->task($actor);
        $this->report($actor);
        $this->meeting($actor);
        $actor->accessRoles()->detach();
        $actor->unsetRelation('accessRoles');
        Sanctum::actingAs($actor);
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        foreach (['task_statuses', 'task_schedule', 'projects', 'reports'] as $key) {
            $this->assertSame(['available' => false, 'reason' => 'missing_module_permission'], $data[$key]);
        }
        foreach ($data['cards'] as $card) {
            $this->assertSame(['available' => false, 'reason' => 'missing_module_permission'], $card);
        }
    }

    public function test_report_action_type_is_optional_nullable_validated_and_persisted_on_create_and_update(): void
    {
        $actor = User::factory()->admin()->create();
        Sanctum::actingAs($actor);
        $payload = ['title' => 'R', 'short_description' => 'S', 'description' => 'D', 'recipient_user_id' => $actor->id];
        $id = $this->postJson('/api/v1/reports', $payload)->assertCreated()->assertJsonPath('data.report.action_type', null)->json('data.report.id');
        $this->patchJson("/api/v1/reports/$id", ['action_type' => 'needs_review'])->assertOk()->assertJsonPath('data.report.action_type', 'needs_review');
        $this->patchJson("/api/v1/reports/$id", ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.report.action_type', 'needs_review');
        $this->patchJson("/api/v1/reports/$id", ['action_type' => null])->assertOk()->assertJsonPath('data.report.action_type', null);
        $this->postJson('/api/v1/reports', [...$payload, 'action_type' => 'needs_instruction'])->assertCreated()->assertJsonPath('data.report.action_type', 'needs_instruction');
        $meeting = $this->meeting($actor, ['status' => 'scheduled']);
        $resolution = MeetingResolution::create(['meeting_id' => $meeting->id, 'title' => 'Report resolution',
            'resolution_type' => 'report', 'created_by' => $actor->id, 'sort_order' => 1]);
        $this->postJson("/api/v1/meetings/{$meeting->id}/resolutions/{$resolution->id}/report",
            [...$payload, 'action_type' => 'needs_action'])->assertCreated()->assertJsonPath('data.report.action_type', 'needs_action');
        $this->postJson('/api/v1/reports', [...$payload, 'action_type' => 'sent'])->assertUnprocessable()->assertJsonValidationErrors('action_type');
        $this->patchJson("/api/v1/reports/$id", ['action_type' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('action_type');
    }

    public function test_each_module_checks_its_own_permission(): void
    {
        $actor = User::factory()->create();
        $this->task($actor);
        $this->report($actor);
        $this->meeting($actor);
        $role = AccessRole::create(['slug' => 'dashboard-task-only', 'name' => 'Tasks only']);
        $role->permissions()->attach(Permission::where('name', Permissions::TASKS_VIEW)->firstOrFail());
        $actor->accessRoles()->sync([$role->id]);
        $actor->unsetRelation('accessRoles');
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_statuses.total', 1)
            ->assertJsonPath('data.cards.active_tasks.count', 1)
            ->assertJsonPath('data.reports.available', false)
            ->assertJsonPath('data.cards.registered_reports.available', false)
            ->assertJsonPath('data.cards.meetings.available', false);
    }

    public function test_query_count_does_not_grow_with_tasks_or_tags(): void
    {
        $actor = User::factory()->create();
        $actor->loadMissing('accessRoles.permissions');
        Sanctum::actingAs($actor);
        $this->task($actor)->tags()->attach(Tag::create(['title' => 'First']));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/dashboard?projects_per_page=100')->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $task = $this->task($actor, ['due_date' => CarbonImmutable::now()->addDays($i)->toDateString()]);
            $task->tags()->attach(Tag::create(['title' => 'Tag '.$i]));
            $task->participantRecords()->create(['user_id' => $actor->id, 'role' => 'follower']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/dashboard?projects_per_page=100')->assertOk()
            ->assertJsonCount(26, 'data.projects.items')
            ->assertJsonPath('data.task_statuses.total', 26)
            ->assertJsonPath('data.task_schedule.examined_total', 26);
        $this->assertLessThanOrEqual($baseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_schedule_percentages_use_only_classified_tasks_and_exclusions_are_disjoint(): void
    {
        $actor = User::factory()->create();
        foreach (['2026-10-20', '2026-10-14', '2026-10-13', null] as $due) {
            $this->task($actor, ['due_date' => $due]);
        }
        $this->task($actor, ['status' => 'completed']);
        $this->task($actor, ['status' => 'draft', 'due_date' => null]);
        Sanctum::actingAs($actor);
        $chart = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_schedule.examined_total', 6)
            ->assertJsonPath('data.task_schedule.total', 3)
            ->assertJsonPath('data.task_schedule.excluded_total', 3)->json('data.task_schedule');
        foreach ($chart['items'] as $item) {
            $this->assertSame(1, $item['count']);
            $this->assertEquals(33.33, $item['percentage']);
        }
        foreach ($chart['excluded_counts'] as $count) {
            $this->assertSame(1, $count);
        }
    }

    public function test_project_with_only_excluded_statuses_has_zero_progress(): void
    {
        $actor = User::factory()->create();
        $this->task($actor, ['status' => 'draft', 'progress_percentage' => 90])->tags()->attach(Tag::create(['title' => 'Draft project']));
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.projects.items.0.tasks_count', 1)
            ->assertJsonPath('data.projects.items.0.completed_count', 0)
            ->assertJsonPath('data.projects.items.0.progress_percentage', 0);
    }

    public function test_every_enum_has_explicit_label_and_dashboard_rules(): void
    {
        $statuses = TaskStatus::cases();
        foreach ($statuses as $status) {
            $this->assertNotEmpty($status->label());
            $rules = $status->dashboardRules();
            $this->assertIsBool($rules['active_task']);
            $this->assertIsBool($rules['active_request']);
            $this->assertContains($rules['schedule'], ['open', 'completed', 'excluded']);
            $this->assertSame($rules['schedule'] !== 'excluded', $rules['project_eligible']);
        }
        $this->assertSame(['ready_to_start', 'in_progress', 'pending_completion_approval'], TaskStatus::dashboardValues('active_task', true));
        $this->assertSame(['pending_approval', 'revision_requested', 'ready_to_start', 'in_progress', 'pending_completion_approval'], TaskStatus::dashboardValues('active_request', true));
        $this->assertSame(['draft', 'not_completed', 'rejected', 'closed'], TaskStatus::dashboardValues('schedule', 'excluded'));
    }

    public function test_completion_requested_on_time_remains_on_time_after_late_manager_approval(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['due_date' => '2026-10-14', 'progress_percentage' => 100]);
        $task->participantRecords()->create(['user_id' => $actor->id, 'role' => 'assignee']);
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();
        $requested = $task->fresh()->completion_requested_at->toISOString();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Tehran'));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.task_schedule.items.2.count', 1);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertOk();
        $this->assertSame($requested, $task->fresh()->completion_requested_at->toISOString());
        $this->assertTrue($task->fresh()->completed_at->gt($task->fresh()->completion_requested_at));
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_schedule.items.0.count', 1)
            ->assertJsonPath('data.task_schedule.items.2.count', 0)
            ->assertJsonPath('data.task_schedule.fallback_completed_at_count', 0);
    }

    public function test_rejected_attempt_is_archived_and_resubmission_time_is_used(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['due_date' => '2026-10-14', 'progress_percentage' => 100]);
        $task->participantRecords()->create(['user_id' => $actor->id, 'role' => 'assignee']);
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();
        $firstTime = $task->fresh()->completion_requested_at->toISOString();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Tehran'));
        $this->postJson("/api/v1/tasks/{$task->id}/completion/reject", ['reason' => 'نیاز به بازبینی'])->assertOk();
        $this->assertNull($task->fresh()->completion_requested_at);
        $rejected = $task->workflowHistory()->where('action', 'completion_rejected')->firstOrFail();
        $this->assertSame($firstTime, $rejected->context['completion_requested_at']);
        $this->assertSame('2026-10-14', $rejected->context['due_date']);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();
        $newTime = $task->fresh()->completion_requested_at->toISOString();
        $this->assertNotSame($firstTime, $newTime);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Tehran'));
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.task_schedule.items.2.count', 1);
        // Changing a deadline does not rewrite either attempt's immutable history context.
        $task->update(['due_date' => '2026-10-20']);
        $this->assertSame('2026-10-14', $rejected->fresh()->context['due_date']);
        $approved = $task->workflowHistory()->where('action', 'completion_approved')->firstOrFail();
        $this->assertSame($newTime, $approved->context['completion_requested_at']);
        $this->assertSame('2026-10-14', $approved->context['due_date']);
        $this->assertSame(2, $task->workflowHistory()->where('action', 'completion_requested')->count());
    }

    public function test_deadline_edit_appends_history_without_rewriting_attempt_context(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['status' => 'draft', 'due_date' => '2026-10-20']);
        $old = $task->workflowHistory()->create(['actor_id' => $actor->id, 'action' => 'completion_rejected',
            'context' => ['completion_requested_at' => '2026-10-13T10:00:00Z', 'due_date' => '2026-10-20']]);
        Sanctum::actingAs($actor);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['due_date' => '2026-10-21'])->assertOk();
        $this->assertSame('2026-10-20', $old->fresh()->context['due_date']);
        $this->assertEquals(['old_due_date' => '2026-10-20', 'new_due_date' => '2026-10-21'],
            $task->workflowHistory()->where('action', 'deadline_updated')->firstOrFail()->context);
    }

    public function test_completion_uses_request_then_historical_fallback_and_excludes_both_missing(): void
    {
        $actor = User::factory()->create();
        $this->task($actor, ['status' => 'completed', 'due_date' => '2026-10-14',
            'completion_requested_at' => '2026-10-14 10:00:00', 'completed_at' => '2026-10-20 10:00:00']);
        $this->task($actor, ['status' => 'completed', 'due_date' => '2026-10-14', 'completed_at' => '2026-10-14 10:00:00']);
        $this->task($actor, ['status' => 'completed', 'due_date' => '2026-10-14',
            'completion_requested_at' => '2026-10-14 10:00:00']);
        $this->task($actor, ['status' => 'completed', 'due_date' => '2026-10-14']);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_schedule.total', 3)
            ->assertJsonPath('data.task_schedule.items.0.count', 3)
            ->assertJsonPath('data.task_schedule.excluded_counts.missing_completed_at', 1)
            ->assertJsonPath('data.task_schedule.fallback_completed_at_count', 1);
    }

    public function test_incomplete_progress_rejected_at_request_and_rechecked_at_approval(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['progress_percentage' => 99]);
        $task->participantRecords()->create(['user_id' => $actor->id, 'role' => 'assignee']);
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertUnprocessable()
            ->assertJsonValidationErrors('progress_percentage');
        $this->assertNull($task->fresh()->completion_requested_at);
        $task->update(['progress_percentage' => 100]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();
        // Simulate a committed change between the request and approval, using a stale route model.
        DB::table('tasks')->where('id', $task->id)->update(['progress_percentage' => 50]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertUnprocessable()
            ->assertJsonValidationErrors('progress_percentage');
        $this->assertSame('pending_completion_approval', $task->fresh()->status->value);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_planning_source_is_rechecked_without_artificially_updating_items(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['progress_percentage' => 100]);
        $task->participantRecords()->create(['user_id' => $actor->id, 'role' => 'assignee']);
        $first = $task->planningItems()->create(['title' => 'A', 'weight' => 20, 'progress_percentage' => 100]);
        $second = $task->planningItems()->create(['title' => 'B', 'weight' => 80, 'progress_percentage' => 50]);
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertUnprocessable();
        $this->assertSame(50, $second->fresh()->progress_percentage);
        $second->update(['progress_percentage' => 100]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();
        $second->update(['progress_percentage' => 50]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertUnprocessable();
        $this->assertSame(100, $first->fresh()->progress_percentage);
        $this->assertSame(50, $second->fresh()->progress_percentage);
        $second->update(['progress_percentage' => 100]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertOk();
    }

    public function test_historical_completed_uses_effective_100_progress_without_mutating_records(): void
    {
        $actor = User::factory()->create();
        $tag = Tag::create(['title' => 'Legacy']);
        $legacy = $this->task($actor, ['status' => 'completed', 'progress_percentage' => 20]);
        $legacy->tags()->attach($tag);
        $item = $legacy->planningItems()->create(['title' => 'Legacy plan', 'weight' => 100, 'progress_percentage' => 20]);
        $this->task($actor, ['progress_percentage' => 50])->tags()->attach($tag);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.projects.items.0.progress_percentage', 75);
        $this->assertSame(20, $legacy->fresh()->progress_percentage);
        $this->assertSame(20, $item->fresh()->progress_percentage);
    }

    public function test_projects_endpoint_matches_legacy_pagination_without_calculating_charts(): void
    {
        $actor = User::factory()->create();
        foreach (['A', 'B'] as $title) {
            $this->task($actor)->tags()->attach(Tag::create(['title' => $title]));
        }
        Sanctum::actingAs($actor);
        $legacy = $this->getJson('/api/v1/dashboard?projects_page=2&projects_per_page=1')->assertOk()->json('data.projects');
        $this->mock(DashboardScheduleStatistics::class, function ($mock): void {
            $mock->shouldNotReceive('build');
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/dashboard/projects?page=2&per_page=1')->assertOk()->assertJsonPath('data', $legacy);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('schedule_source', $query['query']);
            $this->assertStringNotContainsString('reports', $query['query']);
            $this->assertStringNotContainsString('meetings', $query['query']);
        }
        DB::disableQueryLog();
        $this->getJson('/api/v1/dashboard/projects?page=0&per_page=101')->assertUnprocessable();
        $actor->accessRoles()->detach();
        $actor->unsetRelation('accessRoles');
        $this->getJson('/api/v1/dashboard/projects')->assertForbidden();
    }

    public function test_completion_audit_counts_inconsistencies_without_changing_data(): void
    {
        $actor = User::factory()->create();
        $task = $this->task($actor, ['status' => 'completed', 'progress_percentage' => 20, 'completed_at' => '2026-10-14 10:00:00']);
        $task->planningItems()->create(['title' => 'Old plan', 'weight' => 100, 'progress_percentage' => 20]);
        Artisan::call('tasks:audit-completion');
        $counts = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $counts['completed_total']);
        $this->assertSame(1, $counts['inconsistent_completed_count']);
        $this->assertSame(1, $counts['stored_progress_below_100_count']);
        $this->assertSame(1, $counts['planning_progress_below_100_count']);
        $this->assertSame(20, $task->fresh()->progress_percentage);
    }

    public function test_mysql_dashboard_has_repeatable_read_snapshot_despite_read_committed_default(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL/InnoDB concurrency test; run phpunit.mysql.xml.');
        }
        $actor = User::factory()->create();
        $tag = Tag::create(['title' => 'Snapshot']);
        $this->task($actor)->tags()->attach($tag);
        $this->assertSame('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $engines = DB::select('SELECT DISTINCT ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
        $this->assertSame(['InnoDB'], array_column($engines, 'ENGINE'));
        $writer = DB::build(config('database.connections.mysql'));
        $inserted = false;
        DB::listen(function ($event) use ($actor, $tag, $writer, &$inserted): void {
            if (! $inserted && str_contains($event->sql, 'COUNT(*) AS aggregate') && str_contains($event->sql, 'group by `status`')) {
                $inserted = true;
                $id = $writer->table('tasks')->insertGetId([
                    'title' => 'Concurrent task', 'short_description' => 'Concurrent',
                    'submission_type' => 'assignment', 'status' => 'in_progress',
                    'created_by' => $actor->id, 'requester_id' => $actor->id,
                    'due_date' => '2026-10-20', 'progress_percentage' => 0,
                ]);
                $writer->table((new Tag)->tasks()->getTable())->insert(['tag_id' => $tag->id, 'task_id' => $id]);
            }
        });
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.task_statuses.total', 1)
            ->assertJsonPath('data.task_schedule.examined_total', 1)
            ->assertJsonPath('data.cards.active_tasks.count', 1)
            ->assertJsonPath('data.projects.items.0.tasks_count', 1);
        $this->assertTrue($inserted);
        $this->assertSame(2, DB::table('tasks')->count());
        $this->assertSame('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $writer->disconnect();
    }

    public function test_mysql_snapshot_is_read_only_and_rolls_back_on_failure(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL read-only transaction test.');
        }
        try {
            app(DashboardReadSnapshot::class)->run(fn () => DB::table('tags')->insert(['title' => 'Forbidden write']));
            $this->fail('The dashboard transaction must be read-only.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('read only', strtolower($exception->getMessage()));
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertDatabaseMissing('tags', ['title' => 'Forbidden write']);
        $this->assertSame(0, app(DashboardReadSnapshot::class)->run(fn () => DB::table('tags')->count()));
    }

    public function test_explain_dashboard_queries_with_2000_tasks(): void
    {
        [$actor, $other] = User::factory()->count(2)->create()->all();
        $rows = [];
        $participants = [];
        $pivots = [];
        $tags = [];
        for ($i = 1; $i <= 20; $i++) {
            $tags[] = ['id' => $i, 'title' => 'Project '.$i];
        }
        DB::table('tags')->insert($tags);
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = ['id' => $i, 'title' => 'Fixture '.$i, 'short_description' => 'EXPLAIN fixture',
                'created_by' => $other->id, 'requester_id' => $other->id,
                'submission_type' => $i % 3 === 0 ? 'request' : 'assignment',
                'status' => $i % 5 === 0 ? 'completed' : 'in_progress',
                'progress_percentage' => $i % 5 === 0 ? 100 : 50, 'due_date' => '2026-10-20'];
            if ($i % 4 === 0) {
                $participants[] = ['task_id' => $i, 'user_id' => $actor->id, 'role' => 'assignee'];
            }
            $pivots[] = ['task_id' => $i, 'tag_id' => $i % 20 + 1];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('tasks')->insert($chunk);
        }
        foreach (array_chunk($participants, 200) as $chunk) {
            DB::table('task_participants')->insert($chunk);
        }
        foreach (array_chunk($pivots, 200) as $chunk) {
            DB::table((new Tag)->tasks()->getTable())->insert($chunk);
        }
        $visible = app(TaskVisibilityService::class)->visibleQuery($actor);
        $assignments = (clone $visible)->where('submission_type', 'assignment');
        $queries = [
            'visibility' => (clone $visible)->select('tasks.id'),
            'statuses' => (clone $assignments)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status'),
            'projects' => app(DashboardProjectStatistics::class)->query($assignments)->limit(15),
        ];
        $driver = DB::connection()->getDriverName();
        $plans = [];
        foreach ($queries as $name => $query) {
            $plans[$name] = ['sql' => $query->toSql(), 'bindings' => $query->getBindings(),
                'plan' => DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN FORMAT=JSON ').$query->toSql(), $query->getBindings())];
            $this->assertNotEmpty($plans[$name]['plan']);
        }
        $this->assertSame(500, (clone $visible)->count());
        $this->assertSame(334, (clone $assignments)->count());
        $this->assertSame(5, $queries['projects']->get()->count());
        if ($path = getenv('DASHBOARD_EXPLAIN_OUTPUT')) {
            file_put_contents($path, json_encode(['driver' => $driver, 'fixture_tasks' => 2000, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_unclassified_only_reports_have_zero_percentages(): void
    {
        $actor = User::factory()->create();
        $this->report($actor);
        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.reports.total', 1)->assertJsonPath('data.reports.classified_total', 0)
            ->assertJsonPath('data.reports.unclassified_count', 1);
        foreach ($response->json('data.reports.items') as $item) {
            $this->assertSame(0, $item['count']);
            $this->assertEquals(0, $item['percentage']);
        }
    }
}
