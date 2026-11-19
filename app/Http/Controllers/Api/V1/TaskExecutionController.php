<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskSubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskTeamService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskExecutionController extends Controller
{
    private function success(string $message, array $data = []): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public function users(Request $request, TaskTeamService $teams): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission(Permissions::TASKS_VIEW)
            && ($actor->hasPermission(Permissions::TASKS_CREATE) || $actor->hasPermission(Permissions::TASKS_UPDATE)), 403);
        $data = $request->validate(['leader_user_id' => ['required', 'integer', 'exists:users,id']]);
        $leader = User::query()->where('is_active', true)->findOrFail($data['leader_user_id']);
        $allowed = $leader->id === $actor->id || $teams->descendants($actor)->whereKey($leader->id)->exists();
        if (! $allowed) {
            $targets = app(TaskService::class)->eligibleUsers(null, $actor, TaskSubmissionType::Request);
            $allowed = $targets['request_targets']->contains('id', $leader->id);
        }
        if (! $allowed) {
            $allowed = Meeting::query()->whereIn('status', ['draft', 'scheduled'])->whereHas('attendees', fn ($users) => $users->whereKey($leader->id))
                ->get()->contains(fn ($meeting) => Gate::forUser($actor)->allows('manageResolutions', $meeting));
        }
        abort_unless($allowed, 403);

        return $this->success('اعضای مجاز تیم انجام', ['items' => UserSummaryResource::collection($teams->descendants($leader)->orderBy('first_name')->orderBy('last_name')->get())]);
    }

    public function acknowledge(Request $request, Task $task, TaskTeamService $teams): JsonResponse
    {
        Gate::authorize('view', $task);

        return $this->success('مشاهده تسک ثبت شد.', ['task' => new TaskResource($teams->acknowledge($task, $request->user()))]);
    }

    public function transfer(Request $request, Task $task, TaskTeamService $teams): JsonResponse
    {
        Gate::authorize('transfer', $task);
        $data = $request->validate(['assignee_user_id' => ['required', 'integer', 'exists:users,id'], 'team_member_ids' => ['sometimes', 'array', 'max:100'], 'team_member_ids.*' => ['integer', 'distinct', 'exists:users,id']]);

        return $this->success('تسک منتقل شد.', ['task' => new TaskResource($teams->transfer($task, $request->user(), (int) $data['assignee_user_id'], $data['team_member_ids'] ?? null))]);
    }

    public function update(Request $request, Task $task, TaskTeamService $teams): JsonResponse
    {
        Gate::authorize('manageTeam', $task);
        $data = $request->validate(['team_member_ids' => ['present', 'array', 'max:100'], 'team_member_ids.*' => ['integer', 'distinct', 'exists:users,id']]);

        return $this->success('تیم انجام به‌روز شد.', ['task' => new TaskResource($teams->updateTeam($task, $request->user(), $data['team_member_ids']))]);
    }
}
