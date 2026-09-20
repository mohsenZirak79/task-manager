<?php

namespace Tests\Feature;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\AccessRole;
use App\Models\OrgPosition;
use App\Models\Permission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskCommentReaction;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_user_can_create_reply_list_edit_and_soft_delete_comments(): void
    {
        [$task, $owner, $viewer] = $this->context();
        Sanctum::actingAs($owner);

        $rootId = $this->postJson("/api/v1/tasks/{$task->id}/comments", [
            'body' => 'لطفاً این درخواست بررسی شود.',
            'parent_id' => null,
        ])->assertCreated()
            ->assertJsonPath('data.comment.allowed_actions.edit', true)
            ->assertJsonPath('data.comment.reactions.like', 0)
            ->json('data.comment.id');

        Sanctum::actingAs($viewer);
        $replyId = $this->postJson("/api/v1/tasks/{$task->id}/comments", [
            'body' => 'پاسخ دیدگاه',
            'parent_id' => $rootId,
        ])->assertCreated()->json('data.comment.id');

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/tasks/{$task->id}/comments/{$rootId}", ['body' => 'متن ویرایش‌شده'])
            ->assertOk()->assertJsonPath('data.comment.body', 'متن ویرایش‌شده');
        $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$rootId}")->assertOk();

        $this->getJson("/api/v1/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $rootId)
            ->assertJsonPath('data.items.0.body', null)
            ->assertJsonPath('data.items.0.is_deleted', true)
            ->assertJsonPath('data.items.0.replies_count', 1)
            ->assertJsonPath('data.items.0.allowed_actions.edit', false)
            ->assertJsonPath('data.items.0.allowed_actions.react', false);

        $this->getJson("/api/v1/tasks/{$task->id}/comments?parent_id={$rootId}")
            ->assertOk()->assertJsonPath('data.items.0.id', $replyId);
    }

    public function test_task_visibility_and_comment_permission_are_required(): void
    {
        [$task, $owner, $viewer, $outsider, $noComment] = $this->context();

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'غیرمجاز'])->assertForbidden();

        $this->grantOnly($noComment, [Permissions::TASKS_VIEW]);
        Sanctum::actingAs($noComment);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'بدون مجوز'])->assertForbidden();

        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'مجاز'])->assertCreated();
    }

    public function test_only_owner_or_scoped_comment_manager_can_modify_or_delete(): void
    {
        [$task, $owner, $viewer, $outsider, $noComment, $manager] = $this->context();
        Sanctum::actingAs($owner);
        $commentId = $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'متن مالک'])
            ->assertCreated()->json('data.comment.id');

        Sanctum::actingAs($viewer);
        $this->patchJson("/api/v1/tasks/{$task->id}/comments/{$commentId}", ['body' => 'دستکاری'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$commentId}")->assertForbidden();

        $this->grantOnly($manager, [Permissions::TASKS_VIEW, Permissions::TASKS_MANAGE_COMMENTS]);
        Sanctum::actingAs($manager);
        $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$commentId}")->assertOk();
        $this->assertSoftDeleted('task_comments', ['id' => $commentId]);

        Sanctum::actingAs($outsider);
        $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$commentId}")->assertNotFound();
    }

    public function test_comment_and_parent_ids_are_scoped_to_route_task(): void
    {
        [$firstTask, $owner] = $this->context();
        $secondTask = Task::query()->create([
            'title' => 'تسک دوم',
            'short_description' => 'شرح دوم',
            'status' => TaskStatus::Draft,
            'submission_type' => TaskSubmissionType::Request,
            'requester_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $foreignComment = $secondTask->comments()->create([
            'user_id' => $owner->id,
            'body' => 'دیدگاه تسک دوم',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/tasks/{$firstTask->id}/comments", [
            'body' => 'پاسخ جعلی',
            'parent_id' => $foreignComment->id,
        ])->assertNotFound();
        $this->patchJson("/api/v1/tasks/{$firstTask->id}/comments/{$foreignComment->id}", [
            'body' => 'دستکاری جعلی',
        ])->assertNotFound();
        $this->putJson("/api/v1/tasks/{$firstTask->id}/comments/{$foreignComment->id}/reaction", [
            'reaction' => 'like',
        ])->assertNotFound();
    }

    public function test_reaction_can_be_created_changed_removed_and_counted_without_duplicates(): void
    {
        [$task, $owner, $viewer] = $this->context();
        $comment = $task->comments()->create(['user_id' => $owner->id, 'body' => 'دیدگاه']);
        Sanctum::actingAs($viewer);

        $this->putJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}/reaction", ['reaction' => 'like'])
            ->assertOk()
            ->assertJsonPath('data.reactions.like', 1)
            ->assertJsonPath('data.reactions.dislike', 0)
            ->assertJsonPath('data.reactions.my_reaction', 'like');
        $this->putJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}/reaction", ['reaction' => 'dislike'])
            ->assertOk()
            ->assertJsonPath('data.reactions.like', 0)
            ->assertJsonPath('data.reactions.dislike', 1)
            ->assertJsonPath('data.reactions.my_reaction', 'dislike');
        $this->assertDatabaseCount('task_comment_reactions', 1);

        $this->putJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}/reaction", ['reaction' => null])
            ->assertOk()
            ->assertJsonPath('data.reactions.my_reaction', null);
        $this->assertDatabaseCount('task_comment_reactions', 0);

        $comment->delete();
        $this->putJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}/reaction", ['reaction' => 'like'])
            ->assertNotFound();
    }

    public function test_reaction_unique_constraint_blocks_duplicate_rows(): void
    {
        [$task, $owner, $viewer] = $this->context();
        $comment = $task->comments()->create(['user_id' => $owner->id, 'body' => 'دیدگاه']);
        TaskCommentReaction::query()->create([
            'task_comment_id' => $comment->id,
            'user_id' => $viewer->id,
            'reaction' => 'like',
        ]);

        $this->expectException(QueryException::class);
        TaskCommentReaction::query()->create([
            'task_comment_id' => $comment->id,
            'user_id' => $viewer->id,
            'reaction' => 'dislike',
        ]);
    }

    public function test_comment_listing_uses_bounded_queries(): void
    {
        [$task, $owner] = $this->context();
        TaskComment::query()->insert(collect(range(1, 10))->map(fn (int $index) => [
            'task_id' => $task->id,
            'user_id' => $owner->id,
            'parent_id' => null,
            'body' => "دیدگاه {$index}",
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
        Sanctum::actingAs($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/v1/tasks/{$task->id}/comments?per_page=10")
            ->assertOk()->assertJsonCount(10, 'data.items');
        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()));
    }

    /** @return array{Task, User, User, User, User, User} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $noComment = User::factory()->create();

        $root = $this->position('مدیر', $manager);
        $this->position('مالک', $owner, $root);
        $this->position('بیننده', $viewer, $root);
        $this->position('بدون دیدگاه', $noComment, $root);
        $this->position('شاخه نامرتبط', $outsider);

        $task = Task::query()->create([
            'title' => 'درخواست دیدگاه‌دار',
            'short_description' => 'شرح درخواست',
            'status' => TaskStatus::Draft,
            'submission_type' => TaskSubmissionType::Request,
            'requester_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $task->participantRecords()->createMany([
            ['user_id' => $manager->id, 'role' => TaskParticipantRole::Assignee],
            ['user_id' => $viewer->id, 'role' => TaskParticipantRole::Follower],
            ['user_id' => $noComment->id, 'role' => TaskParticipantRole::Supervisor],
        ]);

        return [$task, $owner, $viewer, $outsider, $noComment, $manager];
    }

    private function position(string $title, User $user, ?OrgPosition $parent = null): OrgPosition
    {
        $position = OrgPosition::query()->create(['title' => $title, 'parent_id' => $parent?->id]);
        $position->users()->sync([$user->id]);

        return $position;
    }

    /** @param list<string> $permissions */
    private function grantOnly(User $user, array $permissions): void
    {
        $role = AccessRole::query()->create([
            'name' => 'Custom '.$user->id,
            'slug' => 'custom_'.$user->id,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user->accessRoles()->sync([$role->id]);
        $user->unsetRelation('accessRoles');
    }
}
