<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardProjectStatistics
{
    public function query(Builder $assignments): Builder
    {
        $scope = fn (Builder $query) => $query->mergeConstraintsFrom($assignments);
        $pivot = (new Tag)->tasks()->getTable();
        $average = (clone $assignments)
            ->whereIn('status', TaskStatus::dashboardValues('project_eligible', true))
            ->whereIn('tasks.id', DB::table($pivot)->select('task_id')->whereColumn('tag_id', 'tags.id'))
            ->selectRaw('COALESCE(AVG(CASE WHEN status = ? THEN 100 ELSE COALESCE(progress_percentage, 0) END), 0)', [TaskStatus::Completed->value]);

        return Tag::query()->whereHas('tasks', $scope)
            ->withCount(['tasks as tasks_count' => $scope,
                'tasks as completed_count' => fn (Builder $query) => $scope($query)->where('status', TaskStatus::Completed->value)])
            ->selectSub($average, 'progress_average')->orderBy('tags.id');
    }

    public function build(Builder $assignments, int $page, int $perPage): array
    {
        $tags = $this->query($assignments)->paginate($perPage, ['tags.id', 'tags.title'], 'projects_page', $page);

        return [
            'available' => true,
            'items' => $tags->getCollection()->map(fn (Tag $tag) => [
                'tag_id' => $tag->id, 'title' => $tag->title,
                'tasks_count' => (int) $tag->tasks_count, 'completed_count' => (int) $tag->completed_count,
                'progress_percentage' => round((float) ($tag->progress_average ?? 0), 2, PHP_ROUND_HALF_UP),
            ])->all(),
            'meta' => ['current_page' => $tags->currentPage(), 'last_page' => $tags->lastPage(),
                'per_page' => $tags->perPage(), 'total' => $tags->total()],
        ];
    }
}
