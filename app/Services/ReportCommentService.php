<?php

namespace App\Services;

use App\Models\Report;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportCommentService
{
    public function paginate(Report $report, array $filters): LengthAwarePaginator
    {
        return $report->comments()->withTrashed()->with('user')
            ->orderBy('id', ($filters['sort'] ?? 'oldest') === 'newest' ? 'desc' : 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(Report $report, array $data, User $actor): TaskComment
    {
        return DB::transaction(function () use ($report, $data, $actor): TaskComment {
            if (isset($data['parent_id'])) {
                $parent = $report->comments()->lockForUpdate()->find($data['parent_id']);
                if (! $parent) {
                    throw ValidationException::withMessages(['parent_id' => 'دیدگاه والد باید متعلق به همین گزارش و حذف‌نشده باشد.']);
                }
            }

            return $report->comments()->create([
                'user_id' => $actor->id,
                'body' => $data['body'],
                'parent_id' => $data['parent_id'] ?? null,
            ])->load('user');
        });
    }

    public function update(TaskComment $comment, string $body): TaskComment
    {
        return DB::transaction(function () use ($comment, $body): TaskComment {
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->id);
            $comment->update(['body' => $body]);

            return $comment->load('user');
        });
    }

    public function delete(TaskComment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            TaskComment::query()->lockForUpdate()->findOrFail($comment->id)->delete();
        });
    }
}
