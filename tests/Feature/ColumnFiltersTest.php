<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ColumnFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17T09:00:00Z'));
        Artisan::call('access:sync');
        $this->actor = User::factory()->admin()->create(['username' => 'filter-root']);
        Sanctum::actingAs($this->actor);
    }

    private function task(array $attributes = [], array $assignees = []): Task
    {
        $task = Task::create([
            'title' => 'فروش', 'short_description' => 'خلاصه', 'request_description' => 'شرح درخواست',
            'duration_minutes' => 480, 'due_date' => '2026-11-17', 'status' => 'in_progress',
            'submission_type' => 'assignment', 'requester_id' => $this->actor->id, 'created_by' => $this->actor->id,
            ...$attributes,
        ]);
        if (isset($attributes['created_at'])) {
            $task->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }
        foreach ($assignees as $person) {
            $task->participantRecords()->create(['user_id' => $person->id, 'role' => 'assignee']);
        }

        return $task;
    }

    private function report(array $attributes = []): Report
    {
        $report = Report::create([
            'title' => 'گزارش فروش', 'short_description' => 'خلاصه گزارش', 'description' => 'شرح گزارش',
            'status' => 'sent', 'created_by' => $this->actor->id, 'recipient_user_id' => $this->actor->id,
            ...$attributes,
        ]);
        if (isset($attributes['created_at'])) {
            $report->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $report;
    }

    private function meeting(array $attributes = []): Meeting
    {
        return Meeting::create([
            'title' => 'جلسه فروش', 'short_description' => 'شرح', 'location' => 'دفتر مرکزی',
            'status' => 'scheduled', 'created_by' => $this->actor->id, 'chairman_user_id' => $this->actor->id,
            'secretary_user_id' => $this->actor->id, 'meeting_date' => '2026-11-17', 'start_time' => '09:00',
            ...$attributes,
        ]);
    }

    private function getList(string $module, array $filters = []): TestResponse
    {
        if ($module === 'tasks') {
            $filters = ['scope' => 'all', ...$filters];
        }

        return $this->getJson('/api/v1/'.$module.'?'.http_build_query($filters, '', '&', PHP_QUERY_RFC3986));
    }

    private function ids(TestResponse $response, array $expected): void
    {
        $actual = array_column($response->assertOk()->json('data.items'), 'id');
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function restricted(User $actor, array $permissions): void
    {
        $role = AccessRole::create(['slug' => 'filter-viewer-'.$actor->id, 'name' => 'Filter viewer']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        $actor->accessRoles()->sync([$role->id]);
        $actor->unsetRelation('accessRoles');
        Sanctum::actingAs($actor);
    }

    public function test_task_title_description_and_search_are_independent_and_literal(): void
    {
        $wanted = $this->task(['title' => '  فروش_۲۰% ویژه!  ', 'short_description' => 'گزارش كريم', 'request_description' => 'متن پنهان']);
        $fallback = $this->task(['title' => 'خرید', 'short_description' => null, 'request_description' => 'گزارش كريم']);
        $empty = $this->task(['title' => 'فروش', 'short_description' => '', 'request_description' => 'گزارش كريم']);
        $this->task(['title' => 'فروشX۲۰ZZ ویژه', 'short_description' => 'غیرمرتبط']);
        $this->ids($this->getList('tasks', ['title' => ' _۲۰% ']), [$wanted->id]);
        $this->ids($this->getList('tasks', ['description' => 'کریم']), [$wanted->id, $fallback->id]);
        $this->ids($this->getList('tasks', ['title' => 'فروش', 'description' => 'کریم', 'search' => 'ویژه!']), [$wanted->id]);
        $this->ids($this->getList('tasks', ['description' => 'متن پنهان']), []);
        $this->ids($this->getList('tasks', ['title' => 'خرید', 'search' => 'ویژه']), []);
    }

    public function test_multi_assignee_exact_id_and_full_name_do_not_duplicate_tasks(): void
    {
        $one = User::factory()->create(['first_name' => 'كريم', 'last_name' => 'علي']);
        $two = User::factory()->create(['first_name' => 'ناهید', 'last_name' => 'کاظمی']);
        $target = $this->task([], [$one, $two]);
        $this->task(['title' => 'دیگر'], [$one]);
        $this->ids($this->getList('tasks', ['assignee_id' => $two->id]), [$target->id]);
        $this->ids($this->getList('tasks', ['title' => 'فروش', 'assignee_search' => 'کریم علی']), [$target->id]);
        $this->getList('tasks', ['assignee_id' => $one->id])->assertOk()->assertJsonPath('data.meta.total', 2);
    }

    public function test_task_duration_cost_and_due_ranges_include_both_ends_and_zero(): void
    {
        $first = $this->task(['duration_minutes' => 0, 'financial_estimated_cost' => '0.00', 'equipment_estimated_cost' => '10.25', 'due_date' => '2026-11-16']);
        $last = $this->task(['duration_minutes' => 480, 'financial_estimated_cost' => '100.25', 'equipment_estimated_cost' => '20.50', 'due_date' => '2026-11-18']);
        $this->task(['duration_minutes' => 481, 'financial_estimated_cost' => '100.26', 'equipment_estimated_cost' => '20.51', 'due_date' => '2026-11-19']);
        $this->task(['duration_minutes' => null, 'financial_estimated_cost' => null, 'equipment_estimated_cost' => null, 'due_date' => null]);
        foreach ([['duration_minutes_min' => 0, 'duration_minutes_max' => 480], ['financial_estimated_cost_min' => 0, 'financial_estimated_cost_max' => '100.25'], ['equipment_estimated_cost_min' => '10.25', 'equipment_estimated_cost_max' => '20.50'], ['due_from' => '2026-11-16', 'due_to' => '2026-11-18']] as $filters) {
            $this->ids($this->getList('tasks', $filters), [$first->id, $last->id]);
        }
        $this->ids($this->getList('tasks', ['duration_minutes_min' => 0, 'duration_minutes_max' => 0]), [$first->id]);
        $this->ids($this->getList('tasks', ['due_to' => '2026-11-16']), [$first->id]);
        $this->ids($this->getList('tasks', ['financial_estimated_cost_min' => '100.25', 'financial_estimated_cost_max' => '100.25']), [$last->id]);
    }

    public function test_combined_resource_and_provider_searches_are_grouped_with_other_filters(): void
    {
        $provider = User::factory()->create(['first_name' => 'كريم', 'last_name' => 'علي', 'is_active' => false]);
        $financial = $this->task(['financial_resources' => 'وام فروش', 'equipment_resources' => 'رایانه']);
        $named = $this->task(['title' => 'خرید', 'financial_provider_user_id' => $provider->id, 'equipment_provider_user_id' => $provider->id]);
        $this->task(['title' => 'نامرتبط']);
        $this->ids($this->getList('tasks', ['financial_search' => 'وام']), [$financial->id]);
        $this->ids($this->getList('tasks', ['equipment_search' => 'رایانه']), [$financial->id]);
        foreach (['financial', 'equipment'] as $prefix) {
            $this->ids($this->getList('tasks', [$prefix.'_search' => 'کریم علی']), [$named->id]);
            $this->ids($this->getList('tasks', [$prefix.'_provider_user_id' => $provider->id]), [$named->id]);
            $this->ids($this->getList('tasks', ['title' => 'فروش', $prefix.'_search' => 'کریم']), []);
        }
    }

    public function test_task_legacy_filters_scope_and_user_id_meaning_are_preserved(): void
    {
        $person = User::factory()->create();
        $target = $this->task(['submission_type' => 'request', 'financial_provider_user_id' => $person->id]);
        $target->participantRecords()->create(['role' => 'follower', 'user_id' => $person->id]);
        $target->participantRecords()->create(['role' => 'supervisor', 'user_id' => $person->id]);
        $tag = Tag::create(['title' => 'تگ%_خاص']);
        $target->tags()->attach($tag);
        $this->task();
        foreach (['follower_id', 'supervisor_id', 'user_id'] as $field) {
            $this->ids($this->getList('tasks', [$field => $person->id, 'submission_type' => 'request', 'status' => 'in_progress', 'tag' => '%_']), [$target->id]);
        }
        $this->ids($this->getList('tasks', ['assignee_id' => $person->id]), []);
        $this->ids($this->getList('tasks', ['scope' => 'created_by_me', 'title' => 'فروش']), [$target->id, Task::latest('id')->first()->id]);
    }

    public function test_timestamp_filters_use_tehran_day_with_exclusive_next_midnight(): void
    {
        foreach (['tasks', 'reports'] as $module) {
            $created = [];
            foreach (['2026-11-16 20:29:59', '2026-11-16 20:30:00', '2026-11-17 20:29:59', '2026-11-17 20:30:00'] as $date) {
                $created[] = $module === 'tasks' ? $this->task(['created_at' => $date]) : $this->report(['created_at' => $date]);
            }
            $this->ids($this->getList($module, ['created_from' => '2026-11-17', 'created_to' => '2026-11-17']), [$created[1]->id, $created[2]->id]);
        }
    }

    public function test_report_number_title_names_and_legacy_filters_combine_with_and(): void
    {
        $recipient = User::factory()->create(['first_name' => 'كريم', 'last_name' => 'علي']);
        $creator = User::factory()->create(['first_name' => 'ناهید', 'last_name' => 'کاظمی']);
        $meeting = $this->meeting();
        $resolution = $meeting->resolutions()->create(['title' => 'مصوبه', 'resolution_type' => 'report', 'created_by' => $this->actor->id]);
        $target = $this->report(['title' => 'گزارش_فروش%', 'recipient_user_id' => $recipient->id, 'created_by' => $creator->id, 'meeting_resolution_id' => $resolution->id]);
        $tag = Tag::create(['title' => 'گزارش']);
        $target->tags()->attach($tag);
        $this->report(['title' => 'نامه']);
        foreach (['report_number' => substr($target->report_number, -4), 'title' => '_فروش%', 'recipient_user_id' => $recipient->id, 'recipient_search' => 'کریم علی', 'creator_user_id' => $creator->id, 'creator_search' => 'ناهید کاظمی', 'meeting_id' => $meeting->id, 'resolution_id' => $resolution->id, 'tag_id' => $tag->id] as $field => $value) {
            $this->ids($this->getList('reports', [$field => $value]), [$target->id]);
        }
        $this->ids($this->getList('reports', ['title' => 'فروش', 'recipient_search' => 'کریم علی', 'creator_user_id' => $creator->id, 'search' => 'مصوبه', 'status' => 'sent']), [$target->id]);
        $this->ids($this->getList('reports', ['title' => 'نامه', 'recipient_user_id' => $recipient->id]), []);
    }

    public function test_report_number_falls_back_to_id_only_when_number_is_null(): void
    {
        $report = $this->report();
        DB::table('reports')->where('id', $report->id)->update(['report_number' => null]);
        $this->ids($this->getList('reports', ['report_number' => (string) $report->id]), [$report->id]);
    }

    public function test_meeting_columns_dates_status_search_and_page_are_independent(): void
    {
        $first = $this->meeting(['title' => 'بررسی_فروش%', 'location' => 'دفتر كريم', 'meeting_date' => '2026-11-16']);
        $last = $this->meeting(['title' => 'بررسی_فروش%', 'location' => 'دفتر كريم', 'meeting_date' => '2026-11-18']);
        $this->meeting(['title' => 'خرید', 'location' => 'محل دیگر', 'status' => 'completed']);
        foreach ([['title' => '_فروش%'], ['location' => 'کریم'], ['from_date' => '2026-11-16', 'to_date' => '2026-11-18', 'status' => 'scheduled'], ['search' => 'بررسی', 'location' => 'دفتر', 'title' => 'فروش']] as $filters) {
            $this->ids($this->getList('meetings', $filters), [$first->id, $last->id]);
        }
        $this->ids($this->getList('meetings', ['to_date' => '2026-11-16']), [$first->id]);
        $this->getList('meetings', ['location' => 'کریم', 'per_page' => 1, 'page' => 2])->assertOk()
            ->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.meta.last_page', 2)->assertJsonPath('data.meta.current_page', 2);
    }

    public function test_user_text_columns_are_literal_case_insensitive_and_keep_mobile_zero(): void
    {
        $target = User::factory()->create(['username' => 'col_%!name', 'first_name' => 'كريم', 'last_name' => 'علي', 'email' => 'Case.Filter@Example.test', 'mobile' => '09120012345', 'is_active' => false]);
        User::factory()->create(['username' => 'colXXname', 'mobile' => '09129999999']);
        foreach (['username' => '_%!', 'full_name' => 'کریم علی', 'email' => 'case.filter@EXAMPLE', 'mobile' => '012345'] as $field => $value) {
            $this->ids($this->getList('users', [$field => ' '.$value.' ']), [$target->id]);
        }
        $this->ids($this->getList('users', ['mobile' => '091200', 'full_name' => 'کریم', 'is_active' => 0, 'search' => 'علي']), [$target->id]);
    }

    public function test_user_org_filters_match_any_position_and_never_access_role_title(): void
    {
        $target = User::factory()->create(['username' => 'multi-position']);
        $one = OrgPosition::create(['title' => 'حسابداری']);
        $two = OrgPosition::create(['title' => 'كارشناس فروش']);
        $target->orgPositions()->attach([$one->id, $two->id]);
        $this->ids($this->getList('users', ['org_position_id' => $two->id]), [$target->id]);
        $this->ids($this->getList('users', ['org_position_title' => 'کارشناس']), [$target->id]);
        $this->getList('users', ['org_position_id' => $two->id, 'org_position_title' => 'فروش'])->assertOk()->assertJsonPath('data.meta.total', 1);
        $role = AccessRole::create(['slug' => 'fake-position', 'name' => 'تنها نقش دسترسی']);
        $target->accessRoles()->attach($role);
        $this->ids($this->getList('users', ['org_position_title' => 'تنها نقش دسترسی']), []);
    }

    public function test_user_count_filter_uses_the_same_assignment_count_and_preserves_zero(): void
    {
        $assigned = User::factory()->create(['is_active' => false]);
        $empty = User::factory()->create();
        $task = $this->task([], [$assigned]);
        $task->participantRecords()->create(['user_id' => $empty->id, 'role' => 'follower']);
        $this->task(['status' => 'completed'], [$assigned]);
        $this->ids($this->getList('users', ['tasks_count_min' => 2, 'tasks_count_max' => 2]), [$assigned->id]);
        $this->getList('users', ['tasks_count_min' => 2])->assertOk()->assertJsonPath('data.items.0.tasks_count', 2);
        $this->ids($this->getList('users', ['tasks_count_min' => 0, 'tasks_count_max' => 0]), [$empty->id, $this->actor->id]);
        $this->ids($this->getList('users', ['is_active' => 0, 'tasks_count_min' => 0]), [$assigned->id]);
    }

    public function test_user_login_and_activity_day_ranges_include_ends_and_exclude_null(): void
    {
        foreach (['last_login', 'last_activity'] as $prefix) {
            $ids = [];
            foreach (['2026-11-16 20:29:59', '2026-11-16 20:30:00', '2026-11-17 20:29:59', '2026-11-17 20:30:00', null] as $index => $value) {
                $ids[] = User::factory()->create(['username' => $prefix.'-'.$index, $prefix.'_at' => $value])->id;
            }
            $this->ids($this->getList('users', ['username' => $prefix.'-', $prefix.'_from' => '2026-11-17', $prefix.'_to' => '2026-11-17']), [$ids[1], $ids[2]]);
            $this->ids($this->getList('users', ['username' => $prefix.'-', $prefix.'_to' => '2026-11-16']), [$ids[0]]);
        }
    }

    public function test_filtered_pagination_totals_and_empty_parameters_work_for_all_modules(): void
    {
        $this->task(['title' => 'فیلتر']);
        $this->task(['title' => 'فیلتر']);
        $this->task(['title' => 'دیگر']);
        $this->report(['title' => 'فیلتر']);
        $this->report(['title' => 'فیلتر']);
        $this->report(['title' => 'دیگر']);
        $this->meeting(['title' => 'فیلتر']);
        $this->meeting(['title' => 'فیلتر']);
        $this->meeting(['title' => 'دیگر']);
        User::factory()->create(['username' => 'فیلتر-one']);
        User::factory()->create(['username' => 'فیلتر-two']);
        foreach (['tasks', 'reports', 'meetings', 'users'] as $module) {
            $field = $module === 'users' ? 'username' : 'title';
            $this->getList($module, [$field => 'فیلتر', 'search' => ' ', 'status' => '', 'per_page' => 1, 'page' => 2])->assertOk()
                ->assertJsonCount(1, 'data.items')->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.meta.last_page', 2);
            $this->getList($module, [$field => '', 'per_page' => '', 'page' => ''])->assertOk();
        }
    }

    public function test_independent_filters_never_bypass_module_visibility_or_task_scope(): void
    {
        $viewer = User::factory()->create();
        $this->restricted($viewer, ['tasks:view', 'reports:view', 'meetings:view']);
        $own = $this->task(['title' => 'مجاز'], [$viewer]);
        $this->task(['title' => 'مخفی', 'financial_resources' => 'وام']);
        $this->ids($this->getList('tasks', ['scope' => 'assigned_to_me', 'title' => 'مخفی']), []);
        $this->ids($this->getList('tasks', ['scope' => 'assigned_to_me', 'financial_search' => 'وام']), []);
        $this->ids($this->getList('tasks', ['scope' => 'assigned_to_me', 'title' => 'مجاز']), [$own->id]);
        $this->getList('tasks', ['scope' => 'all'])->assertForbidden();
        $this->report(['title' => 'گزارش مخفی']);
        $this->meeting(['title' => 'جلسه مخفی']);
        $this->ids($this->getList('reports', ['title' => 'مخفی']), []);
        $this->ids($this->getList('meetings', ['title' => 'مخفی']), []);
        $this->getList('users')->assertForbidden();
    }

    public function test_user_filters_do_not_reveal_super_admin_to_regular_user_viewers(): void
    {
        $viewer = User::factory()->create();
        $this->restricted($viewer, ['users:view']);
        $this->ids($this->getList('users', ['username' => 'filter-root']), []);
        $this->ids($this->getList('users', ['username' => $viewer->username]), [$viewer->id]);
    }

    public function test_invalid_parameters_return_422_on_the_same_field(): void
    {
        $invalid = [
            'tasks' => ['title' => str_repeat('ع', 256), 'description' => ['bad'], 'duration_minutes_min' => -1, 'duration_minutes_max' => '1.5', 'financial_estimated_cost_min' => 'bad', 'equipment_estimated_cost_max' => -2, 'assignee_id' => 'bad', 'financial_provider_user_id' => 999999, 'due_from' => '1405/08/26', 'due_to' => '2026-02-30', 'created_from' => '2026-11-17T00:00:00Z', 'status' => 'bad', 'page' => 0],
            'reports' => ['report_number' => ['x'], 'recipient_search' => str_repeat('x', 256), 'creator_user_id' => 999999, 'created_to' => 'yesterday', 'status' => 'bad'],
            'meetings' => ['location' => ['x'], 'title' => str_repeat('x', 256), 'from_date' => '2026-1-1', 'page' => 0],
            'users' => ['mobile' => ['x'], 'full_name' => str_repeat('x', 256), 'is_active' => 2, 'org_position_id' => 999999, 'tasks_count_max' => -1, 'last_login_from' => '2026-02-30', 'last_activity_to' => 'not-date'],
        ];
        foreach ($invalid as $module => $parameters) {
            foreach ($parameters as $field => $value) {
                $this->getList($module, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        foreach ([['tasks', 'duration_minutes_min', 'duration_minutes_max', 20, 10], ['tasks', 'financial_estimated_cost_min', 'financial_estimated_cost_max', '1000000000000000.02', '1000000000000000.01'], ['tasks', 'equipment_estimated_cost_min', 'equipment_estimated_cost_max', 20, 10], ['tasks', 'due_from', 'due_to', '2026-11-18', '2026-11-17'], ['reports', 'created_from', 'created_to', '2026-11-18', '2026-11-17'], ['meetings', 'from_date', 'to_date', '2026-11-18', '2026-11-17'], ['users', 'tasks_count_min', 'tasks_count_max', 2, 1], ['users', 'last_login_from', 'last_login_to', '2026-11-18', '2026-11-17'], ['users', 'last_activity_from', 'last_activity_to', '2026-11-18', '2026-11-17']] as [$module, $from, $to, $lower, $upper]) {
            $this->getList($module, [$from => $lower, $to => $upper])->assertUnprocessable()->assertJsonValidationErrors($to);
        }
    }

    public function test_task_lookup_covers_inactive_related_people_and_keeps_scope_and_permissions(): void
    {
        $viewer = User::factory()->create();
        $inactive = User::factory()->create(['first_name' => 'كريم', 'last_name' => 'علي', 'is_active' => false]);
        $outside = User::factory()->create();
        $visible = $this->task([], [$inactive]);
        $visible->participantRecords()->create(['role' => 'follower', 'user_id' => $viewer->id]);
        $this->task([], [$outside]);
        $this->restricted($viewer, ['tasks:view']);
        $url = '/api/v1/tasks/filter-options/users?scope=involved&field=assignee_id';
        $this->ids($this->getJson($url.'&search='.urlencode('کریم علی')), [$inactive->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.is_active', false);
        $this->getJson('/api/v1/tasks/eligible-users')->assertForbidden();
        $this->getJson($url.'&scope=all')->assertForbidden();
        $this->getJson($url.'&field=users.password')->assertUnprocessable()->assertJsonValidationErrors('field');
    }

    public function test_task_lookup_supports_every_existing_person_filter_and_pagination(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = $this->task(['financial_provider_user_id' => $first->id, 'equipment_provider_user_id' => $second->id], [$first, $second]);
        $task->participantRecords()->create(['role' => 'follower', 'user_id' => $first->id]);
        $task->participantRecords()->create(['role' => 'supervisor', 'user_id' => $second->id]);
        foreach (['follower_id' => $first, 'supervisor_id' => $second, 'financial_provider_user_id' => $first, 'equipment_provider_user_id' => $second] as $field => $person) {
            $this->ids($this->getJson('/api/v1/tasks/filter-options/users?scope=all&field='.$field), [$person->id]);
        }
        $this->getJson('/api/v1/tasks/filter-options/users?scope=all&field=assignee_id&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.meta.last_page', 2)->assertJsonPath('data.items.0.id', $second->id);
        $this->ids($this->getJson('/api/v1/tasks/filter-options/users?scope=all&field=user_id'), [$first->id, $second->id, $this->actor->id]);
        $this->ids($this->getJson('/api/v1/tasks/filter-options/users?scope=all&submission_type=request'), []);
    }

    public function test_report_lookup_uses_view_permission_and_includes_inactive_historical_people(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create(['is_active' => false]);
        $recipient = User::factory()->create(['is_active' => false]);
        $visible = $this->report(['created_by' => $creator->id, 'recipient_user_id' => $recipient->id]);
        $visible->ccUsers()->attach($viewer);
        $this->report();
        $this->restricted($viewer, ['reports:view']);
        foreach (['creator_user_id' => $creator, 'recipient_user_id' => $recipient] as $field => $person) {
            $this->ids($this->getJson('/api/v1/reports/filter-options/users?field='.$field), [$person->id]);
        }
        $this->getJson('/api/v1/reports/eligible-users')->assertForbidden();
        $this->getJson('/api/v1/tasks/filter-options/users')->assertForbidden();
    }

    public function test_user_position_lookup_needs_users_view_and_only_shows_positions_of_visible_users(): void
    {
        $viewer = User::factory()->create();
        $user = User::factory()->create(['is_active' => false]);
        $position = OrgPosition::create(['title' => 'كارشناس%_']);
        $position->users()->attach($user);
        $hidden = OrgPosition::create(['title' => 'مدیر مخفی']);
        $hidden->users()->attach($this->actor);
        $this->restricted($viewer, ['users:view']);
        $this->ids($this->getJson('/api/v1/users/filter-options/org-positions?search='.urlencode('کارشناس%_')), [$position->id]);
        $this->ids($this->getJson('/api/v1/users/filter-options/org-positions'), [$position->id]);
        $this->getJson('/api/v1/org-positions')->assertForbidden();
        $this->getJson('/api/v1/users/filter-options/org-positions?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }
}
