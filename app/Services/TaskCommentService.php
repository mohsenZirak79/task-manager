<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskCommentReaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TaskCommentService
{
    public function paginate(Task $task, array $filters, User $actor): LengthAwarePaginator
    {
        if (array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null) {
            $this->findForTask($task, (int) $filters['parent_id'], true);
        }

        $query = TaskComment::query()
            ->withTrashed()
            ->where('task_id', $task->id)
            ->when(
                array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null,
                fn ($query) => $query->where('parent_id', $filters['parent_id']),
                fn ($query) => $query->whereNull('parent_id'),
            )
            ->with(['user', 'task.participantRecords', 'reactions' => fn ($query) => $query->where('user_id', $actor->id)])
            ->withCount([
                'replies',
                'reactions as like_count' => fn ($query) => $query->where('reaction', 'like'),
                'reactions as dislike_count' => fn ($query) => $query->where('reaction', 'dislike'),
            ]);

        ($filters['sort'] ?? 'oldest') === 'newest' ? $query->latest() : $query->oldest();

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(Task $task, array $data, User $actor): TaskComment
    {
        return DB::transaction(function () use ($task, $data, $actor): TaskComment {
            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                $this->findForTask($task, (int) $parentId, true);
            }

            $comment = $task->comments()->create([
                'user_id' => $actor->id,
                'parent_id' => $parentId,
                'body' => $data['body'],
            ]);

            return $this->loadForResponse($comment, $actor);
        });
    }

    public function update(TaskComment $comment, string $body, User $actor): TaskComment
    {
        return DB::transaction(function () use ($comment, $body, $actor): TaskComment {
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->id);
            $comment->update(['body' => $body]);

            return $this->loadForResponse($comment, $actor);
        });
    }

    public function delete(TaskComment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            TaskComment::query()->lockForUpdate()->findOrFail($comment->id)->delete();
        });
    }

    public function react(TaskComment $comment, ?string $reaction, User $actor): array
    {
        return DB::transaction(function () use ($comment, $reaction, $actor): array {
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->id);
            if ($comment->trashed()) {
                throw new HttpException(409, 'واکنش روی دیدگاه حذف‌شده مجاز نیست.');
            }

            if ($reaction === null) {
                $comment->reactions()->where('user_id', $actor->id)->delete();
            } else {
                $comment->reactions()->updateOrCreate(
                    ['user_id' => $actor->id],
                    ['reaction' => $reaction],
                );
            }

            return $this->reactionSummary($comment, $actor);
        });
    }

    public function findForTask(Task $task, int $commentId, bool $withTrashed = false): TaskComment
    {
        $query = TaskComment::query()->where('task_id', $task->id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail($commentId);
    }

    private function loadForResponse(TaskComment $comment, User $actor): TaskComment
    {
        return TaskComment::query()
            ->withTrashed()
            ->with(['user', 'task.participantRecords', 'reactions' => fn ($query) => $query->where('user_id', $actor->id)])
            ->withCount([
                'replies',
                'reactions as like_count' => fn ($query) => $query->where('reaction', 'like'),
                'reactions as dislike_count' => fn ($query) => $query->where('reaction', 'dislike'),
            ])
            ->findOrFail($comment->id);
    }

    private function reactionSummary(TaskComment $comment, User $actor): array
    {
        return [
            'like' => $comment->reactions()->where('reaction', 'like')->count(),
            'dislike' => $comment->reactions()->where('reaction', 'dislike')->count(),
            'my_reaction' => TaskCommentReaction::query()
                ->where('task_comment_id', $comment->id)
                ->where('user_id', $actor->id)
                ->value('reaction'),
        ];
    }
}
