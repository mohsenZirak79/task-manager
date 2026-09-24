<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\AccessRole;
use App\Models\MediaFile;
use App\Models\OrgPosition;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_defaults_to_creator_and_rejects_every_other_assignee(): void
    {
        [$creator, $other] = $this->organization();
        $unrelated = User::factory()->create();
        $this->position('شاخه نامرتبط', $unrelated);
        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
        ])->assertCreated()
            ->assertJsonPath('data.task.status', TaskStatus::Draft->value)
            ->assertJsonPath('data.task.creator.id', $creator->id)
            ->assertJsonPath('data.task.requester.id', $creator->id)
            ->assertJsonPath('data.task.assignees.0.id', $creator->id);

        foreach ([[], [$other->id], [$creator->id, $other->id], [$unrelated->id]] as $ids) {
            $this->postJson('/api/v1/tasks', [
                ...$this->validPayload(),
                'assignee_ids' => $ids,
            ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
        }

        $targets = $this->getJson('/api/v1/tasks/eligible-users?submission_type=assignment')
            ->assertOk()->json('data.assignment_targets');
        $targetIds = collect($targets)->pluck('id');
        $this->assertCount(1, $targetIds);
        $this->assertTrue($targetIds->contains($creator->id));
        $this->assertFalse($targetIds->contains($other->id));
        $this->assertFalse($targetIds->contains($unrelated->id));
    }

    public function test_assignment_review_revision_resubmit_and_ready_state_are_actor_bound(): void
    {
        [$creator, $assignee, $other] = $this->organization();
        $task = $this->submitAssignment($creator, $assignee);
        $this->assertSame(TaskStatus::PendingApproval, $task->status);

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")->assertForbidden();
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")->assertForbidden();

        Sanctum::actingAs($assignee);
        $this->getJson('/api/v1/tasks?submission_type=assignment&scope=action_required')
            ->assertOk()->assertJsonPath('data.items.0.id', $task->id)
            ->assertJsonPath('data.items.0.allowed_actions.approve_assignment', true)
            ->assertJsonPath('data.items.0.allowed_actions.request_assignment_revision', true);
        $this->postJson("/api/v1/tasks/{$task->id}/request-revision", ['reason' => 'شرح نیاز به اصلاح دارد.'])
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::RevisionRequested->value);

        Sanctum::actingAs($creator);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'عنوان اصلاح‌شده'])
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::RevisionRequested->value);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['submit' => true])
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::ReadyToStart->value)
            ->assertJsonPath('data.task.allowed_actions.start', true)
            ->assertJsonPath('data.task.started_at', null);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")->assertStatus(409);

        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $assignee->id,
            'action' => 'assignee_revision', 'reason' => 'شرح نیاز به اصلاح دارد.',
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $assignee->id, 'action' => 'assignee_approved',
        ]);
    }

    public function test_only_assignee_can_start_ready_assignment(): void
    {
        [$creator, $assignee] = $this->organization();
        $task = $this->registerAssignment($creator, $assignee);

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertForbidden();

        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);
        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 10])
            ->assertForbidden();
        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertStatus(409);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed'])->assertStatus(409);
        $this->assertNotNull(Task::query()->findOrFail($task->id)->started_at);
    }

    public function test_planning_progress_auto_starts_and_recalculates_weighted_progress(): void
    {
        [$creator, $assignee] = $this->organization();
        $task = $this->registerAssignment($creator, $assignee, [
            ['title' => 'مرحله اول', 'weight' => 30, 'sort_order' => 1],
            ['title' => 'مرحله دوم', 'weight' => 70, 'sort_order' => 2],
        ])->fresh('planningItems');
        [$first, $second] = $task->planningItems;

        Sanctum::actingAs($creator);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$first->id}", [
            'progress_percentage' => 10,
        ])->assertForbidden();

        Sanctum::actingAs($assignee);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$first->id}", [
            'progress_percentage' => 50,
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.task.progress_percentage', 15);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$second->id}", [
            'progress_percentage' => 100,
        ])->assertOk()->assertJsonPath('data.task.progress_percentage', 85)
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$first->id}", [
            'progress_percentage' => 100,
        ])->assertOk()->assertJsonPath('data.task.progress_percentage', 100)
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value);
        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 20])
            ->assertStatus(409);

        $other = $this->registerAssignment($creator, $assignee, [
            ['title' => 'آیتم دیگر', 'weight' => 0],
        ])->fresh('planningItems');
        Sanctum::actingAs($assignee);
        $this->patchJson("/api/v1/tasks/{$task->id}/planning-items/{$other->planningItems->first()->id}", [
            'progress_percentage' => 20,
        ])->assertNotFound();
        $otherItem = $other->planningItems->first();
        $this->patchJson("/api/v1/tasks/{$other->id}/planning-items/{$otherItem->id}", [
            'progress_percentage' => 40,
        ])->assertOk()->assertJsonPath('data.task.progress_percentage', 40);

        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $assignee->id, 'action' => 'task_started',
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $assignee->id, 'action' => 'planning_progress',
        ]);
    }

    public function test_assignment_completion_requires_assignee_request_and_creator_review(): void
    {
        [$creator, $assignee, $other] = $this->organization();
        $task = $this->registerAssignment($creator, $assignee);
        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::PendingCompletionApproval->value);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertStatus(409);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertForbidden();
        Sanctum::actingAs($creator);
        $this->getJson('/api/v1/tasks?submission_type=assignment&scope=action_required')
            ->assertOk()->assertJsonPath('data.items.0.id', $task->id)
            ->assertJsonPath('data.items.0.allowed_actions.approve_completion', true)
            ->assertJsonPath('data.items.0.allowed_actions.reject_completion', true);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/reject", ['reason' => 'نیاز به تکمیل دارد.'])
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::InProgress->value);

        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 100])->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => TaskStatus::InProgress->value]);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")->assertOk();

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")
            ->assertOk()->assertJsonPath('data.task.status', TaskStatus::Completed->value);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/approve")->assertStatus(409);
        $this->assertNotNull(Task::query()->findOrFail($task->id)->completed_at);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $creator->id,
            'action' => 'completion_rejected', 'reason' => 'نیاز به تکمیل دارد.',
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id, 'actor_id' => $creator->id, 'action' => 'completion_approved',
        ]);
    }

    public function test_super_admin_can_choose_any_active_assignee_but_cannot_bypass_actor_roles(): void
    {
        $creator = User::factory()->admin()->create();
        $assignee = User::factory()->create();
        $otherAdmin = User::factory()->admin()->create();
        Sanctum::actingAs($creator);
        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(), 'assignee_ids' => [$assignee->id], 'submit' => true,
        ])->assertCreated()->json('data.task.id');

        Sanctum::actingAs($otherAdmin);
        $this->postJson("/api/v1/tasks/{$taskId}/approve")->assertForbidden();
        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$taskId}/approve")->assertOk();
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
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$manager->id],
            'submission_type' => TaskSubmissionType::Request->value,
            'tags' => ['درخواست'],
            'follower_ids' => [$worker->id],
            'supervisor_ids' => [$worker->id],
            'submit' => true,
        ])->assertCreated()
            ->assertJsonPath('data.task.submission_type', 'request')
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$peer->id],
            'submission_type' => TaskSubmissionType::Request->value,
            'tags' => ['درخواست'],
            'follower_ids' => [$worker->id],
            'supervisor_ids' => [$worker->id],
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
            ->assertJsonPath('data.task.status', TaskStatus::ReadyToStart->value)
            ->assertJsonPath('data.task.allowed_actions.start', true)
            ->assertJsonPath('data.task.allowed_actions.update_progress', false);
        $this->postJson("/api/v1/tasks/{$approveTask->id}/status", [
            'status' => TaskStatus::InProgress->value,
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.task.allowed_actions.update_progress', true);
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

    public function test_only_admin_can_close_a_rejected_request(): void
    {
        [$manager, $worker] = $this->organization();
        $task = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$task->id}/reject", [
            'reason' => 'امکان انجام این درخواست وجود ندارد.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::Rejected->value)
            ->assertJsonPath('data.task.allowed_actions.close', false);

        $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => TaskStatus::Closed->value,
        ])->assertForbidden();

        $admin = User::factory()->create();
        $adminRole = AccessRole::query()->where('slug', AccessRoles::ADMIN)->firstOrFail();
        $admin->accessRoles()->syncWithoutDetaching([$adminRole->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/tasks?submission_type=request&scope=action_required')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $task->id);
        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.close', true);
        $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => TaskStatus::Closed->value,
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::Closed->value)
            ->assertJsonPath('data.task.allowed_actions.close', false);

        $this->assertNotNull(Task::query()->findOrFail($task->id)->closed_at);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id,
            'actor_id' => $admin->id,
            'action' => 'closed',
            'from_status' => TaskStatus::Rejected->value,
            'to_status' => TaskStatus::Closed->value,
        ]);
    }

    public function test_request_is_visible_to_but_actionable_only_by_the_selected_reviewer(): void
    {
        [$reviewer, $requester, $other] = $this->organization();
        $request = $this->submitRequest($requester, $reviewer);

        Sanctum::actingAs($requester);
        $this->getJson('/api/v1/tasks?submission_type=request')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $request->id)
            ->assertJsonPath('data.items.0.allowed_actions.approve', false)
            ->assertJsonPath('data.items.0.allowed_actions.reject', false)
            ->assertJsonPath('data.items.0.allowed_actions.request_revision', false)
            ->assertJsonPath('data.items.0.allowed_actions.edit', false);
        $this->postJson("/api/v1/tasks/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$request->id}/reject", ['reason' => 'رد'])->assertForbidden();
        $this->postJson("/api/v1/tasks/{$request->id}/request-revision", ['reason' => 'اصلاح'])->assertForbidden();

        Sanctum::actingAs($reviewer);
        $this->getJson('/api/v1/tasks?submission_type=request')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $request->id)
            ->assertJsonPath('data.items.0.allowed_actions.approve', true)
            ->assertJsonPath('data.items.0.allowed_actions.reject', true)
            ->assertJsonPath('data.items.0.allowed_actions.request_revision', true)
            ->assertJsonPath('data.items.0.allowed_actions.edit', false);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/tasks/{$request->id}")->assertForbidden();

        $observer = User::factory()->admin()->create();
        Sanctum::actingAs($observer);
        $this->getJson("/api/v1/tasks/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.approve', false)
            ->assertJsonPath('data.task.allowed_actions.reject', false)
            ->assertJsonPath('data.task.allowed_actions.request_revision', false)
            ->assertJsonPath('data.task.allowed_actions.edit', false);
        $this->postJson("/api/v1/tasks/{$request->id}/approve")->assertForbidden();

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/v1/tasks/{$request->id}/request-revision", [
            'reason' => 'شرح درخواست نیاز به اصلاح دارد.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::RevisionRequested->value)
            ->assertJsonPath('data.task.allowed_actions.approve', false)
            ->assertJsonPath('data.task.allowed_actions.reject', false)
            ->assertJsonPath('data.task.allowed_actions.request_revision', false);

        Sanctum::actingAs($requester);
        $this->getJson("/api/v1/tasks/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.edit', true)
            ->assertJsonPath('data.task.allowed_actions.approve', false)
            ->assertJsonPath('data.task.allowed_actions.reject', false)
            ->assertJsonPath('data.task.allowed_actions.request_revision', false);
    }

    public function test_only_request_assignee_can_see_and_submit_progress_after_approval(): void
    {
        [$assignee, $requester] = $this->organization();
        $request = $this->submitRequest($requester, $assignee);

        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::ReadyToStart->value)
            ->assertJsonPath('data.task.allowed_actions.start', true)
            ->assertJsonPath('data.task.allowed_actions.update_progress', false);
        $this->postJson("/api/v1/tasks/{$request->id}/status", [
            'status' => TaskStatus::InProgress->value,
        ])->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.task.allowed_actions.update_progress', true);

        Sanctum::actingAs($requester);
        $this->getJson("/api/v1/tasks/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.update_progress', false);
        $this->postJson("/api/v1/tasks/{$request->id}/progress", [
            'progress_percentage' => 25,
        ])->assertForbidden();

        $observer = User::factory()->admin()->create();
        Sanctum::actingAs($observer);
        $this->getJson("/api/v1/tasks/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.update_progress', false);
        $this->postJson("/api/v1/tasks/{$request->id}/progress", [
            'progress_percentage' => 25,
        ])->assertForbidden();

        Sanctum::actingAs($assignee);
        $this->getJson("/api/v1/tasks/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.task.allowed_actions.update_progress', true);
        $this->postJson("/api/v1/tasks/{$request->id}/progress", [
            'progress_percentage' => 40,
        ])->assertOk()
            ->assertJsonPath('data.task.progress_percentage', 40);
    }

    public function test_assignee_can_record_progress_and_status_changes_are_logged(): void
    {
        [$manager, $worker] = $this->organization();
        $task = $this->registerAssignment($manager, $worker);
        Sanctum::actingAs($worker);
        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => TaskStatus::InProgress->value])
            ->assertOk();

        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 65])
            ->assertOk()
            ->assertJsonPath('data.task.progress_percentage', 65);

        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id,
            'action' => 'progress_updated',
            'old_progress' => 0,
            'new_progress' => 65,
        ]);
        $this->assertDatabaseHas('task_workflow_histories', [
            'task_id' => $task->id,
            'action' => 'task_started',
            'from_status' => TaskStatus::ReadyToStart->value,
            'to_status' => TaskStatus::InProgress->value,
        ]);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.task.workflow_history.0.action', 'progress_updated');
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

        $this->getJson('/api/v1/tasks?'.http_build_query(['search' => 'ویژه', 'scope' => 'all']))
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?status=completed&scope=all')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->id);
        $this->getJson("/api/v1/tasks?user_id={$other->id}&scope=all")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?scope=all&created_from='.now()->subDays(2)->toDateString())
            ->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/tasks?scope=all&created_to='.now()->subDays(2)->toDateString())
            ->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/tasks?sort=longest_duration&scope=all')
            ->assertOk()->assertJsonPath('data.items.0.id', $second->id);
        $this->getJson('/api/v1/tasks?sort=lowest_progress&per_page=2&page=2&scope=all')
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
        $this->getJson('/api/v1/tasks?submission_type=request&scope=created_by_me')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $request->id);

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=action_required')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        Sanctum::actingAs($peer);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=involved')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/tasks?submission_type=request&scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_task_dates_duration_descriptions_and_progress_are_validated_on_create_and_update(): void
    {
        [$manager, $worker] = $this->organization();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/tasks', [
            'title' => '',
            'submission_type' => TaskSubmissionType::Assignment->value,
            'short_description' => str_repeat('a', 101),
            'duration_minutes' => 0,
            'due_date' => now()->subDay()->toDateString(),
            'progress_percentage' => 101,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title', 'short_description', 'duration_minutes', 'due_date', 'progress_percentage',
            ]);

        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$worker->id],
        ])
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
            'submission_type' => TaskSubmissionType::Request->value,
        ])->assertCreated()
            ->assertJsonPath('data.task.status', TaskStatus::Draft->value);

        $this->postJson('/api/v1/tasks', [
            'title' => 'ارسال ناقص',
            'short_description' => 'شرح کوتاه',
            'submission_type' => TaskSubmissionType::Request->value,
            'submit' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'duration_minutes', 'due_date', 'assignee_ids', 'tags', 'follower_ids', 'supervisor_ids',
            ]);
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
            ->assertJsonPath('data.task.status', TaskStatus::ReadyToStart->value);
    }

    public function test_eligible_users_are_grouped_and_do_not_expose_sensitive_fields(): void
    {
        [$root, $middle, $worker] = $this->deepOrganization();
        $unrelated = User::factory()->create(['first_name' => 'Unrelated']);
        $rootPosition = $root->orgPositions()->firstOrFail();
        $this->position('شاخه دیگر', $unrelated, $rootPosition);

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
            'submission_type' => TaskSubmissionType::Request->value,
            'follower_ids' => [$peer->id],
        ])->assertForbidden();
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        [$manager, $worker] = $this->organization();
        $task = $this->registerAssignment($manager, $worker);
        Sanctum::actingAs($worker);

        $this->postJson("/api/v1/tasks/{$task->id}/status", ['status' => TaskStatus::Completed->value])
            ->assertStatus(409);
        $this->postJson("/api/v1/tasks/{$task->id}/progress", ['progress_percentage' => 50])
            ->assertStatus(409);
        $this->postJson("/api/v1/tasks/{$task->id}/completion/request")
            ->assertStatus(409);
    }

    public function test_request_draft_preserves_type_and_syncs_planning_items_and_attachments(): void
    {
        Storage::fake('public');
        [$manager, $worker] = $this->organization();
        Sanctum::actingAs($worker);

        $fileId = $this->post('/api/v1/uploads/files', [
            'file' => UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file.category', 'attachment')
            ->json('data.file.id');

        $response = $this->postJson('/api/v1/tasks', [
            'submission_type' => TaskSubmissionType::Request->value,
            'attachment_file_ids' => [$fileId],
            'planning_items' => [
                ['title' => 'تحلیل', 'weight' => 40, 'progress_percentage' => 10, 'sort_order' => 2],
                ['title' => 'اجرا', 'weight' => 60],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.task.submission_type', 'request')
            ->assertJsonPath('data.task.title', null)
            ->assertJsonCount(1, 'data.task.attachments')
            ->assertJsonCount(2, 'data.task.planning_items');

        $taskId = $response->json('data.task.id');
        $this->assertDatabaseMissing('tasks', ['id' => $taskId, 'submission_type' => null]);

        $this->patchJson("/api/v1/tasks/{$taskId}", [
            'planning_items' => [
                ['title' => 'نسخه نهایی', 'weight' => 100, 'progress_percentage' => 25, 'sort_order' => 0],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'data.task.planning_items')
            ->assertJsonPath('data.task.planning_items.0.title', 'نسخه نهایی');

        $this->assertDatabaseCount('task_planning_items', 1);
        $this->assertDatabaseHas('media_file_task', ['task_id' => $taskId, 'media_file_id' => $fileId]);
    }

    public function test_request_draft_can_be_completed_and_submitted_without_creating_another_task(): void
    {
        [$manager, $worker] = $this->organization();
        Sanctum::actingAs($worker);

        $taskId = $this->postJson('/api/v1/tasks', [
            'submission_type' => TaskSubmissionType::Request->value,
        ])->assertCreated()->json('data.task.id');

        $this->patchJson("/api/v1/tasks/{$taskId}", ['submit' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title', 'short_description', 'duration_minutes', 'due_date',
                'assignee_ids', 'follower_ids', 'supervisor_ids', 'tags',
            ]);

        $this->patchJson("/api/v1/tasks/{$taskId}", [
            ...$this->validPayload(),
            'submission_type' => TaskSubmissionType::Request->value,
            'assignee_ids' => [$manager->id],
            'follower_ids' => [$worker->id],
            'supervisor_ids' => [$worker->id],
            'tags' => ['فوری'],
            'submit' => true,
        ])->assertOk()
            ->assertJsonPath('data.task.id', $taskId)
            ->assertJsonPath('data.task.submission_type', 'request')
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value);

        $this->assertDatabaseCount('tasks', 1);
        $this->patchJson("/api/v1/tasks/{$taskId}", ['submission_type' => 'assignment'])->assertForbidden();
    }

    public function test_attachment_ownership_and_safe_deletion_are_enforced(): void
    {
        Storage::fake('public');
        [$manager, $worker, $other] = $this->organization();

        Sanctum::actingAs($worker);
        $ownedResponse = $this->post('/api/v1/uploads/files', [
            'file' => UploadedFile::fake()->create('owned.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $ownedId = $ownedResponse->json('data.file.id');
        $ownedPath = MediaFile::query()->findOrFail($ownedId)->path;

        Sanctum::actingAs($other);
        $foreignId = $this->post('/api/v1/uploads/files', [
            'file' => UploadedFile::fake()->image('foreign.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.file.id');
        $this->deleteJson("/api/v1/uploads/files/{$ownedId}")->assertForbidden();

        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', [
            'submission_type' => TaskSubmissionType::Request->value,
            'attachment_file_ids' => [$foreignId],
        ])->assertForbidden();

        $taskId = $this->postJson('/api/v1/tasks', [
            'submission_type' => TaskSubmissionType::Request->value,
            'attachment_file_ids' => [$ownedId],
        ])->assertCreated()->json('data.task.id');
        $this->deleteJson("/api/v1/uploads/files/{$ownedId}")->assertStatus(409);

        $this->patchJson("/api/v1/tasks/{$taskId}", ['attachment_file_ids' => []])->assertOk();
        $this->deleteJson("/api/v1/uploads/files/{$ownedId}")->assertOk();
        Storage::disk('public')->assertMissing($ownedPath);
    }

    public function test_eligible_users_support_create_and_edit_context(): void
    {
        [$manager, $worker, $other] = $this->organization();
        Sanctum::actingAs($worker);

        $this->getJson('/api/v1/tasks/eligible-users?submission_type=request&search='.urlencode('مدیر'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_targets', 'request_targets', 'participants']])
            ->assertJsonCount(0, 'data.assignment_targets');

        $taskId = $this->postJson('/api/v1/tasks', [
            'submission_type' => TaskSubmissionType::Request->value,
        ])->assertCreated()->json('data.task.id');
        $this->getJson("/api/v1/tasks/eligible-users?task_id={$taskId}&submission_type=request")->assertOk();

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/tasks/eligible-users?task_id={$taskId}")->assertForbidden();
    }

    public function test_request_workflow_preserves_type_and_invalid_transition_returns_conflict(): void
    {
        [$manager, $worker] = $this->organization();

        $approved = $this->submitRequest($worker, $manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$approved->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.task.submission_type', 'request');
        $this->postJson("/api/v1/tasks/{$approved->id}/approve")->assertStatus(409);

        $rejected = $this->submitRequest($worker, $manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$rejected->id}/reject", ['reason' => 'رد تستی'])
            ->assertOk()->assertJsonPath('data.task.submission_type', 'request');

        $revision = $this->submitRequest($worker, $manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$revision->id}/request-revision", ['reason' => 'اصلاح تستی'])
            ->assertOk()->assertJsonPath('data.task.submission_type', 'request');
    }

    public function test_request_list_actions_tags_and_detail_contract_are_available(): void
    {
        [$manager, $worker] = $this->organization();
        $requestTask = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=action_required&tag='.urlencode('درخواست'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $requestTask->id)
            ->assertJsonPath('data.items.0.allowed_actions.approve', true)
            ->assertJsonMissingPath('data.items.0.workflow_history');

        $this->getJson("/api/v1/tasks/{$requestTask->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['task' => [
                'attachments', 'planning_items', 'allowed_actions', 'workflow_history',
            ]]])
            ->assertJsonPath('data.task.allowed_actions.approve', true);

        $this->getJson('/api/v1/task-tags?search='.urlencode('درخواست'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.title', 'درخواست');
    }

    public function test_new_task_requires_a_non_null_submission_type(): void
    {
        [$manager] = $this->organization();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/tasks', ['title' => 'بدون نوع'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submission_type');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_visibility_scopes_defaults_detail_and_pagination_are_enforced(): void
    {
        [$manager, $worker, $peer] = $this->organization();
        $request = $this->submitRequest($worker, $manager);

        Sanctum::actingAs($worker);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=created_by_me')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $request->id);
        $this->getJson('/api/v1/tasks?submission_type=request')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=created_by_me')
            ->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/tasks?submission_type=request')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $request->id);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=action_required')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $request->id);

        Sanctum::actingAs($peer);
        $this->getJson('/api/v1/tasks?submission_type=request&scope=involved')
            ->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/v1/tasks/{$request->id}")->assertForbidden();
        $this->getJson('/api/v1/tasks?scope=all')->assertForbidden();

        Sanctum::actingAs($manager);
        $assignmentId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$manager->id],
            'follower_ids' => [$peer->id],
            'submit' => true,
        ])->assertCreated()->json('data.task.id');
        Sanctum::actingAs($worker);
        $this->getJson('/api/v1/tasks?submission_type=assignment')
            ->assertOk()->assertJsonPath('data.meta.total', 0);

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?submission_type=assignment')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $assignmentId);

        Sanctum::actingAs($peer);
        $this->getJson('/api/v1/tasks?submission_type=assignment&scope=assigned_to_me')
            ->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/tasks?submission_type=assignment&scope=involved')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $assignmentId);

        Sanctum::actingAs($worker);
        $this->postJson('/api/v1/tasks', ['submission_type' => 'request'])->assertCreated();
        $this->getJson('/api/v1/tasks?submission_type=request&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(1, 'data.items');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/tasks?scope=all')
            ->assertOk()->assertJsonPath('data.meta.total', 3);
    }

    private function organization(): array
    {
        $manager = User::factory()->create();
        $worker = User::factory()->create();
        $peer = User::factory()->create();
        $follower = User::factory()->create();
        $supervisor = User::factory()->create();

        $root = $this->position('مدیر', $manager);
        foreach ([$worker, $peer, $follower, $supervisor] as $index => $user) {
            $this->position('کارشناس '.($index + 1), $user, $root);
        }

        return [$manager, $worker, $peer, $follower, $supervisor];
    }

    private function validPayload(): array
    {
        return [
            'title' => 'تسک آزمایشی',
            'short_description' => 'شرح مختصر معتبر',
            'submission_type' => TaskSubmissionType::Assignment->value,
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
            'submission_type' => TaskSubmissionType::Request->value,
            'assignee_ids' => $assigneeIds ?? [$superior->id],
            'follower_ids' => [$requester->id],
            'supervisor_ids' => [$requester->id],
            'tags' => ['درخواست'],
            'submit' => true,
        ])->assertCreated()->json('data.task.id');

        return Task::query()->findOrFail($taskId);
    }

    private function submitAssignment(User $creator, User $assignee, array $planningItems = []): Task
    {
        Sanctum::actingAs($creator);
        $taskId = $this->postJson('/api/v1/tasks', [
            ...$this->validPayload(),
            'assignee_ids' => [$assignee->id],
            'planning_items' => $planningItems,
            'submit' => true,
        ])->assertCreated()
            ->assertJsonPath('data.task.status', TaskStatus::PendingApproval->value)
            ->json('data.task.id');

        return Task::query()->findOrFail($taskId);
    }

    private function registerAssignment(User $creator, User $assignee, array $planningItems = []): Task
    {
        $task = $this->submitAssignment($creator, $assignee, $planningItems);
        Sanctum::actingAs($assignee);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.task.status', TaskStatus::ReadyToStart->value);

        return Task::query()->findOrFail($task->id);
    }

    private function deepOrganization(): array
    {
        $root = User::factory()->create(['first_name' => 'ریشه']);
        $middle = User::factory()->create(['first_name' => 'میانی']);
        $worker = User::factory()->create(['first_name' => 'کاربر']);
        $rootPosition = $this->position('ریشه', $root);
        $middlePosition = $this->position('میانی', $middle, $rootPosition);
        $this->position('کارشناس', $worker, $middlePosition);

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

    private function position(string $title, User $user, ?OrgPosition $parent = null): OrgPosition
    {
        $position = OrgPosition::query()->create([
            'title' => $title,
            'parent_id' => $parent?->id,
        ]);
        $position->users()->sync([$user->id]);

        return $position;
    }
}
