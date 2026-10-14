<?php

namespace App\Services;

use App\Models\Report;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportCommentService
{
    public function paginate(Report $report, array $filters, User $actor): LengthAwarePaginator
    {
        return $this->withResponseRelations($report->comments()->withTrashed()->getQuery(), $actor)
            ->orderBy('id', ($filters['sort'] ?? 'oldest') === 'newest' ? 'desc' : 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(Report $report, array $data, User $actor): TaskComment
    {
        return DB::transaction(function () use ($report, $data, $actor): TaskComment {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            app(ReportService::class)->ensureActive($report);
            if (isset($data['parent_id'])) {
                $parent = $report->comments()->lockForUpdate()->find($data['parent_id']);
                if (! $parent) {
                    throw ValidationException::withMessages(['parent_id' => 'دیدگاه والد باید متعلق به همین گزارش و حذف‌نشده باشد.']);
                }
            }

            $comment = $report->comments()->create([
                'user_id' => $actor->id,
                'body' => $data['body'],
                'parent_id' => $data['parent_id'] ?? null,
            ]);

            app(CommentMentionService::class)->sync($comment, $report, $data);

            return $this->loadForResponse($comment, $actor);
        });
    }

    public function update(TaskComment $comment, string $body, User $actor, array $data = []): TaskComment
    {
        return DB::transaction(function () use ($comment, $body, $actor, $data): TaskComment {
            $report = Report::query()->lockForUpdate()->findOrFail($comment->report_id);
            app(ReportService::class)->ensureActive($report);
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->id);
            $comment->update(['body' => $body]);
            app(CommentMentionService::class)->sync($comment, $report, $data);

            return $this->loadForResponse($comment, $actor);
        });
    }

    public function delete(TaskComment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            $report = Report::query()->lockForUpdate()->findOrFail($comment->report_id);
            app(ReportService::class)->ensureActive($report);
            TaskComment::query()->lockForUpdate()->findOrFail($comment->id)->delete();
        });
    }

    public function react(Report $report, TaskComment $comment, ?string $reaction, User $actor): array
    {
        return DB::transaction(function () use ($report, $comment, $reaction, $actor): array {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            app(ReportService::class)->ensureActive($report);

            // Reuse the existing model, unique constraint and serialized reaction updates.
            return app(TaskCommentService::class)->react($comment, $reaction, $actor);
        });
    }

    private function withResponseRelations(Builder $query, User $actor): Builder
    {
        return $query->with(['user', 'mentions', 'report.ccUsers', 'report.resolution.meeting.attendees', 'reactions' => fn ($query) => $query->where('user_id', $actor->id)])
            ->withCount([
                'reactions as like_count' => fn ($query) => $query->where('reaction', 'like'),
                'reactions as dislike_count' => fn ($query) => $query->where('reaction', 'dislike'),
            ]);
    }

    private function loadForResponse(TaskComment $comment, User $actor): TaskComment
    {
        return $this->withResponseRelations(TaskComment::query(), $actor)->findOrFail($comment->id);
    }
}
