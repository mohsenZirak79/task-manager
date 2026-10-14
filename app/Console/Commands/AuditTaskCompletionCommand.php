<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditTaskCompletionCommand extends Command
{
    protected $signature = 'tasks:audit-completion';

    protected $description = 'Read-only counts of historical completion/progress inconsistencies; never repairs data';

    public function handle(): int
    {
        $planning = DB::table('task_planning_items')->select('task_id')->groupBy('task_id')
            ->selectRaw('CASE WHEN SUM(weight) > 0 THEN ROUND(SUM(weight * progress_percentage) / SUM(weight)) ELSE ROUND(AVG(progress_percentage)) END AS planning_progress');
        $counts = Task::query()->where('status', TaskStatus::Completed->value)
            ->leftJoinSub($planning, 'planning', fn ($join) => $join->on('tasks.id', '=', 'planning.task_id'))
            ->selectRaw('COUNT(*) AS completed_total,
                COALESCE(SUM(CASE WHEN COALESCE(tasks.progress_percentage, 0) < 100 THEN 1 ELSE 0 END), 0) AS stored_progress_below_100_count,
                COALESCE(SUM(CASE WHEN planning.planning_progress < 100 THEN 1 ELSE 0 END), 0) AS planning_progress_below_100_count,
                COALESCE(SUM(CASE WHEN COALESCE(tasks.progress_percentage, 0) < 100 OR planning.planning_progress < 100 THEN 1 ELSE 0 END), 0) AS inconsistent_completed_count,
                COALESCE(SUM(CASE WHEN completion_requested_at IS NULL AND completed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS fallback_completed_at_count,
                COALESCE(SUM(CASE WHEN completion_requested_at IS NULL AND completed_at IS NULL THEN 1 ELSE 0 END), 0) AS missing_completion_time_count')
            ->toBase()->first();
        $this->line(json_encode(array_map('intval', (array) $counts), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
