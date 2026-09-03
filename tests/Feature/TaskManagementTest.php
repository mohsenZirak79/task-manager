<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_can_be_created_and_edited_with_multiple_participants(): void
    {
        [$manager, $worker, $secondWorker, $follower, $supervisor] = $this->organization();
        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id, $secondWorker->id],
            'follower_ids' => [$follower->id],
            'supervisor_ids' => [$supervisor->id],
            'financial_resources' => 'بودجه جاری',
            'financial_estimated_cost' => 125000,
            'financial_provider_user_id' => $follower->id,
            'equipment_resources' => 'دو لپ‌تاپ',
            'equipment_estimated_cost' => 80000,
            'equipment_provider_user_id' => $supervisor->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.status', TaskStatus::Draft->value)
            ->assertJsonCount(2, 'data.task.assignees')
            ->assertJsonCount(1, 'data.task.followers')
            ->assertJsonCount(1, 'data.task.supervisors');

        $taskId = $response->json('data.task.id');

        $this->patchJson("/api/v1/tasks/{$taskId}", [
            'title' => 'عنوان ویرایش‌شده',
            'duration_minutes' => 180,
            'assignee_ids' => [$worker->id],
        ])->assertOk()
            ->assertJsonPath('data.task.title', 'عنوان ویرایش‌شده')
            ->assertJsonPath('data.task.duration_minutes', 180)
            ->assertJsonCount(1, 'data.task.assignees');

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'created_by' => $manager->id]);
        $this->assertDatabaseCount('task_participants', 3);
    }

    public function test_submission_obeys_the_organizational_direction(): void
    {
        [$manager, $worker, $peer] = $this->organization();

        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id],
            'submit' => true,
        ])->assertCreated()
            ->assertJsonPath('data.task.submission_type', 'assignment')
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);

        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$manager->id],
            'submit' => true,
        ])->assertCreated()
            ->assertJsonPath('data.task.submission_type', 'request')
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$peer->id],
            'submit' => true,
        ])->assertForbidden();
    }

    public function test_only_the_authorized_superior_can_approve_or_reject_a_request(): void
    {
        [$manager, $worker, $peer] = $this->organization();

        $approveTask = $this->submitRequest($worker, $manager);
        Sanctum::actingAs($peer);
        $this->postJson("/api/v1/tasks/{$approveTask->id}/approve")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$approveTask->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $approveTask->id,
            'actor_id' => $manager->id,
            'action' => 'approved',
        ]);

        $rejectTask = $this->submitRequest($worker, $manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$rejectTask->id}/reject", [
            'reason' => 'اطلاعات درخواست کافی نیست.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::Rejected->value)
            ->assertJsonPath('data.task.rejection_reason', 'اطلاعات درخواست کافی نیست.');
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $rejectTask->id,
            'action' => 'rejected',
            'reason' => 'اطلاعات درخواست کافی نیست.',
        ]);
    }

    public function test_assignee_can_record_progress_and_status_changes_are_logged(): void
    {
        [$manager, $worker] = $this->organization();
        Sanctum::actingAs($manager);
        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id],
            'submit' => true,
        ])->assertCreated()->json('data.task.id');

        Sanctum::actingAs($worker);
        $this->postJson("/api/v1/tasks/{$taskId}/progress", ['progress_percentage' => 65])
            ->assertOk()
            ->assertJsonPath('data.task.progress_percentage', 65);
        $this->postJson("/api/v1/tasks/{$taskId}/status", ['status' => TaskStatus::Completed->value])
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::Completed->value);

        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $taskId,
            'action' => 'progress_updated',
            'old_progress' => 0,
            'new_progress' => 65,
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $taskId,
            'action' => 'status_changed',
            'from_status' => TaskStatus::InProgress->value,
            'to_status' => TaskStatus::Completed->value,
        ]);

        $this->getJson("/api/v1/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.task.workflow_history.0.action', 'status_changed');
    }

    public function test_list_supports_search_filters_sorting_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $first = $this->makeTask($admin, [
            'title' => 'گزارش مالی',
            'duration_minutes' => 30,
            'progress_percentage' => 80,
            'status' => TaskStatus::Completed,
        ], now()->subDays(3));
        $second = $this->makeTask($other, [
            'title' => 'برنامه فنی',
            'short_description' => 'شامل عبارت ویژه',
            'duration_minutes' => 240,
            'progress_percentage' => 20,
            'status' => TaskStatus::InProgress,
        ], now()->subDay());
        $this->makeTask($admin, [
            'title' => 'کار سوم',
            'duration_minutes' => 90,
            'progress_percentage' => 50,
            'status' => TaskStatus::Draft,
        ], now());

        $this->getJson('/api/v1/tasks?'.http_build_query(['search' => 'ویژه']))
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?status=completed')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->id);
        $this->getJson("/api/v1/tasks?user_id={$other->id}")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?created_from='.now()->subDays(2)->toDateString())
            ->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/tasks?created_to='.now()->subDays(2)->toDateString())
            ->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/tasks?sort=longest_duration')
            ->assertOk()->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?sort=lowest_progress&per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->id);
    }

    public function test_request_list_filter_is_visible_only_to_involved_users_and_all_admins(): void
    {
        [$manager, $worker, $peer] = $this->organization();
        $request = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($worker);
        $this->getJson('/api/v1/tasks?submission_type='.TaskSubmissionType::Request->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $request->id);

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?submission_type='.TaskSubmissionType::Request->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        Sanctum::actingAs($peer);
        $this->getJson('/api/v1/tasks?submission_type='.TaskSubmissionType::Request->value)
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/tasks?submission_type='.TaskSubmissionType::Request->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_task_dates_duration_descriptions_and_progress_are_validated_on_create_and_update(): void
    {
        [$manager] = $this->organization();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/tasks', [
            'title' => '',
            'short_description' => str_repeat('a', 101),
            'duration_minutes' => 0,
            'due_date' => now()->subDay()->toDateString(),
            'progress_percentage' => 101,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title', 'short_description', 'duration_minutes', 'due_date', 'progress_percentage',
            ]);

        $taskId = $this->postJson('/api/v1/tasks', $this->validPayload())
            ->assertCreated()->json('data.task.id');
        $this->patchJson("/api/v1/tasks/{$taskId}", [
            'duration_minutes' => -5,
            'due_date' => 'not-a-date',
            'progress_percentage' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes', 'due_date', 'progress_percentage']);
    }

    public function test_submit_requires_duration_due_date_and_assignee_but_drafts_do_not(): void
    {
        [$manager] = $this->organization();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/tasks', [
            'title' => 'پیش‌نویس بدون زمان',
            'short_description' => 'شرح کوتاه',
        ])->assertCreated()
            ->assertJsonPath('data.task.status', TaskStatus::Draft->value);

        $this->postJson('/api/v1/tasks', [
            'title' => 'ارسال ناقص',
            'short_description' => 'شرح کوتاه',
            'submit' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes', 'due_date', 'assignee_ids']);
    }

    public function test_revision_request_can_be_edited_and_resubmitted(): void
    {
        [$manager, $worker] = $this->organization();
        $task = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$task->id}/request-revision", [
            'reason' => 'لطفاً شرح درخواست را تکمیل کنید.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::RevisionRequested->value);

        Sanctum::actingAs($worker);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'title' => 'عنوان اصلاح‌شده',
            'request_description' => 'شرح تکمیل‌شده',
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::Draft->value)
            ->assertJsonPath('data.task.rejection_reason', null);

        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'submit' => true,
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id,
            'action' => 'revision_requested',
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id,
            'action' => 'revision_edit_started',
        ]);
    }

    public function test_one_of_multiple_superiors_can_approve_a_request(): void
    {
        [$root, $middle, $worker] = $this->deepOrganization();
        $task = $this->submitRequest($worker, $root, [$middle->id, $root->id]);

        Sanctum::actingAs($middle);
        $this->postJson("/api/v1/tasks/{$task->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);
    }

    public function test_eligible_users_are_grouped_and_do_not_expose_sensitive_fields(): void
    {
        [$root, $middle, $worker] = $this->deepOrganization();
        $unrelated = User::factory()->create(['first_name' => 'Unrelated']);
        Role::query()->create([
            'title' => 'شاخه دیگر',
            'parent_id' => $root->role->id,
            'user_id' => $unrelated->id,
        ]);

        Sanctum::actingAs($worker);
        $this->getJson('/api/v1/tasks/eligible-users?search='.urlencode('کاربر'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_targets', 'request_targets', 'participants']])
            ->assertJsonMissingPath('data.assignment_targets.0.mobile')
            ->assertJsonMissingPath('data.assignment_targets.0.email');

        $response = $this->getJson('/api/v1/tasks/eligible-users')->assertOk();
        $assignmentIds = collect($response->json('data.assignment_targets'))->pluck('id')->all();
        $requestIds = collect($response->json('data.request_targets'))->pluck('id')->all();
        $participantIds = collect($response->json('data.participants'))->pluck('id')->all();
        $this->assertContains($worker->id, $assignmentIds);
        $this->assertNotContains($root->id, $assignmentIds);
        $this->assertContains($root->id, $requestIds);
        $this->assertContains($middle->id, $requestIds);
        $this->assertNotContains($unrelated->id, $participantIds);
    }

    public function test_role_direction_limits_are_enforced_when_submitting_tasks_and_requests(): void
    {
        [$root, $middle, $worker] = $this->deepOrganization();

        $worker->role->update(['request_up_levels' => 1]);
        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$root->id],
            'submit' => true,
        ])->assertForbidden();

        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$middle->id],
            'submit' => true,
        ])->assertCreated()
            ->assertJsonPath('data.task.submission_type', TaskSubmissionType::Request->value);

        $root->role->update(['assignment_down_levels' => 1]);
        Sanctum::actingAs($root);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id],
            'submit' => true,
        ])->assertForbidden();
    }

    public function test_unrelated_participants_and_unauthorized_operations_are_rejected(): void
    {
        [$manager, $worker, $peer] = $this->organization();
        $task = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($peer);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/reject", ['reason' => 'رد'])->assertForbidden();
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'غیرمجاز'])->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => TaskStatus::Completed->value])->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 20])->assertForbidden();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'حتی ادمین هم در انتظار تأیید ویرایش نمی‌کند'])
            ->assertForbidden();

        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$manager->id],
            'follower_ids' => [$peer->id],
        ])->assertForbidden();
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        [$manager, $worker] = $this->organization();
        Sanctum::actingAs($manager);
        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id],
            'submit' => true,
        ])
            ->assertCreated()->json('data.task.id');

        Sanctum::actingAs($worker);
        $this->postJson("/api/v1/tasks/{$taskId}/status", ['status' => TaskStatus::Completed->value])
            ->assertOk();
        $this->postJson("/api/v1/tasks/{$taskId}/status", ['status' => TaskStatus::InProgress->value])
            ->assertUnprocessable();
        $this->postJson("/api/v1/tasks/{$taskId}/progress", ['progress_percentage' => 50])
            ->assertForbidden();
    }

    private function organization(): array
    {
        $manager = User::factory()->create();
        $worker = User::factory()->create();
        $peer = User::factory()->create();
        $follower = User::factory()->create();
        $supervisor = User::factory()->create();

        $root = Role::query()->create(['title' => 'مدیر', 'user_id' => $manager->id]);
        foreach ([$worker, $peer, $follower, $supervisor] as $index => $user) {
            Role::query()->create([
                'title' => 'کارشناس '.($index + 1),
                'parent_id' => $root->id,
                'user_id' => $user->id,
            ]);
        }

        return [$manager, $worker, $peer, $follower, $supervisor];
    }

    private function validPayload(): array
    {
        return [
            'title' => 'تسک آزمایشی',
            'short_description' => 'شرح مختصر معتبر',
            'request_description' => 'شرح کامل درخواست',
            'duration_minutes' => 120,
            'due_date' => now()->addWeek()->toDateString(),
            'progress_percentage' => 0,
        ];
    }

    private function submitRequest(User $requester, User $superior, ?array $assigneeIds = null): Task
    {
        Sanctum::actingAs($requester);
        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => $assigneeIds ?? [$superior->id],
            'submit' => true,
        ])->assertCreated()->json('data.task.id');

        return Task::query()->findOrFail($taskId);
    }

    private function deepOrganization(): array
    {
        $root = User::factory()->create(['first_name' => 'ریشه']);
        $middle = User::factory()->create(['first_name' => 'میانی']);
        $worker = User::factory()->create(['first_name' => 'کاربر']);
        $rootRole = Role::query()->create(['title' => 'ریشه', 'user_id' => $root->id]);
        $middleRole = Role::query()->create(['title' => 'میانی', 'parent_id' => $rootRole->id, 'user_id' => $middle->id]);
        Role::query()->create(['title' => 'کارشناس', 'parent_id' => $middleRole->id, 'user_id' => $worker->id]);

        return [$root, $middle, $worker];
    }

    private function makeTask(User $creator, array $attributes, $createdAt): Task
    {
        $task = Task::query()->create([
            'title' => 'تسک',
            'short_description' => 'شرح',
            'duration_minutes' => 60,
            'progress_percentage' => 0,
            'status' => TaskStatus::Draft,
            'requester_id' => $creator->id,
            'created_by' => $creator->id,
            ...$attributes,
        ]);
        $task->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $task;
    }
}
