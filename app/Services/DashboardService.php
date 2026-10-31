<?php

namespace App\Services;

use App\Enums\ReportActionType;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Meeting;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class DashboardService
{
    public function __construct(
        private readonly TaskVisibilityService $tasks,
        private readonly ReportService $reports,
        private readonly MeetingService $meetings,
        private readonly DashboardScheduleStatistics $schedule,
        private readonly DashboardProjectStatistics $projects,
        private readonly DashboardReadSnapshot $snapshot,
    ) {}

    public function build(User $actor, int $page, int $perPage): array
    {
        return $this->snapshot->run(fn () => $this->buildSnapshot($actor, $page, $perPage));
    }

    private function buildSnapshot(User $actor, int $page, int $perPage): array
    {
        $now = CarbonImmutable::now('Asia/Tehran');
        $actor->loadMissing('accessRoles.permissions');
        $unavailable = ['available' => false, 'reason' => 'missing_module_permission'];
        $data = [
            'generated_at' => $now->toIso8601String(),
            'timezone' => 'Asia/Tehran',
            'percentage_denominators' => [
                'task_statuses' => 'task_statuses.total', 'task_schedule' => 'task_schedule.total',
                'reports' => 'reports.classified_total',
            ],
            'cards' => [],
            'task_statuses' => $unavailable,
            'task_schedule' => $unavailable,
            'projects' => $unavailable,
            'reports' => $unavailable,
        ];

        if (Gate::forUser($actor)->allows('viewAny', Task::class)) {
            $visible = $this->tasks->visibleQuery($actor);
            $assignments = (clone $visible)->where('submission_type', TaskSubmissionType::Assignment->value);
            $counts = (clone $assignments)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->all();
            $data['task_statuses'] = [
                'available' => true,
                'total' => (int) array_sum($counts),
                'items' => $this->items(array_column(array_map(fn (TaskStatus $status) => ['key' => $status->value, 'label' => $status->label()], TaskStatus::cases()), 'label', 'key'), $counts, (int) array_sum($counts)),
            ];
            $data['task_schedule'] = $this->schedule->build($assignments, $now);
            $data['projects'] = $this->projects->build($assignments, $page, $perPage);
            $data['cards']['active_tasks'] = $this->card((clone $assignments)->whereIn('status', TaskStatus::dashboardValues('active_task', true))->count());
            $data['cards']['active_requests'] = $this->card((clone $visible)->where('submission_type', TaskSubmissionType::Request->value)->whereIn('status', TaskStatus::dashboardValues('active_request', true))->count());
            $actionTasks = $this->tasks->apply((clone $visible), $actor, TaskVisibilityService::ACTION_REQUIRED);
            $data['cards']['active_tasks']['action_required_count'] = (clone $actionTasks)->where('submission_type', TaskSubmissionType::Assignment->value)->count();
            $data['cards']['active_requests']['action_required_count'] = (clone $actionTasks)->where('submission_type', TaskSubmissionType::Request->value)->count();
        } else {
            $data['cards']['active_tasks'] = $unavailable;
            $data['cards']['active_requests'] = $unavailable;
        }

        if (Gate::forUser($actor)->allows('viewAny', Report::class)) {
            $counts = $this->reports->visibleQuery($actor)->whereIn('status', [ReportStatus::Sent->value, ReportStatus::Viewed->value])
                ->selectRaw('action_type, COUNT(*) AS aggregate')->groupBy('action_type')->pluck('aggregate', 'action_type')->all();
            $total = (int) array_sum($counts);
            $unclassified = (int) ($counts[''] ?? 0);
            $data['reports'] = [
                'available' => true, 'total' => $total, 'classified_total' => $total - $unclassified,
                'unclassified_count' => $unclassified,
                'items' => $this->items([
                    ReportActionType::NeedsAction->value => 'نیازمند اقدام',
                    ReportActionType::NeedsReview->value => 'نیازمند بررسی',
                    ReportActionType::NeedsInstruction->value => 'نیازمند دستور',
                ], $counts, $total - $unclassified),
            ];
            $data['cards']['registered_reports'] = $this->card($total);
            $data['cards']['registered_reports']['action_required_count'] = $this->reports->visibleQuery($actor)->where('status', ReportStatus::Sent->value)->where('recipient_user_id', $actor->id)->count();
        } else {
            $data['cards']['registered_reports'] = $unavailable;
        }
        $data['cards']['meetings'] = Gate::forUser($actor)->allows('viewAny', Meeting::class)
            ? $this->card($this->meetings->visibleQuery($actor)->count()) : $unavailable;

        if ($data['cards']['meetings']['available']) {
            $data['cards']['meetings']['action_required_count'] = $this->meetings->actionRequiredQuery($actor)->count();
        }

        return $data;
    }

    public function projects(User $actor, int $page, int $perPage): array
    {
        return $this->snapshot->run(function () use ($actor, $page, $perPage): array {
            $actor->loadMissing('accessRoles.permissions');
            Gate::forUser($actor)->authorize('viewAny', Task::class);

            return $this->projects->build($this->tasks->visibleQuery($actor)
                ->where('submission_type', TaskSubmissionType::Assignment->value), $page, $perPage);
        });
    }

    private function card(int $count): array
    {
        return ['available' => true, 'count' => $count];
    }

    private function items(array $labels, array $counts, int $total): array
    {
        $items = [];
        foreach ($labels as $key => $label) {
            $count = (int) ($counts[$key] ?? 0);
            $items[] = ['key' => $key, 'label' => $label, 'count' => $count,
                'percentage' => $total > 0 ? round($count * 100 / $total, 2, PHP_ROUND_HALF_UP) : 0.0];
        }

        return $items;
    }
}
