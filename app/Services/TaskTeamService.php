<?php

namespace App\Services;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Models\OrgPosition;
use App\Models\Task;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskTeamService
{
    public function descendants(User $leader): Builder
    {
        $roots = $leader->orgPositions()->pluck('org_positions.id')->map(fn ($id) => (int) $id)->all();
        $parents = OrgPosition::query()->pluck('parent_id', 'id')->all();
        $positions = [];
        foreach ($parents as $id => $parent) {
            $visited = [(int) $id];
            while ($parent !== null && ! in_array((int) $parent, $visited, true)) {
                if (in_array((int) $parent, $roots, true)) {
                    $positions[] = (int) $id;
                    break;
                }
                $visited[] = (int) $parent;
                $parent = $parents[$parent] ?? null;
            }
        }

        return User::query()->where('is_active', true)->whereKeyNot($leader->id)
            ->whereHas('orgPositions', fn (Builder $query) => $query->whereIn('org_positions.id', $positions));
    }

    public function isLeader(User $actor, Task $task): bool
    {
        return $actor->is_active && ($task->relationLoaded('participantRecords')
            ? $task->participantRecords->contains(fn ($p) => $p->user_id === $actor->id && $p->role === TaskParticipantRole::Assignee)
            : $task->participantRecords()->where('role', 'assignee')->where('user_id', $actor->id)->exists());
    }

    public function isMember(User $actor, Task $task): bool
    {
        return $actor->is_active && ($task->relationLoaded('teamMembers')
            ? $task->teamMembers->contains('id', $actor->id)
            : $task->teamMembers()->whereKey($actor->id)->exists());
    }

    public function validate(array $data, ?Task $task = null, bool $validateAllMembers = true): void
    {
        $members = array_map('intval', $data['team_member_ids'] ?? $task?->teamMembers()->pluck('users.id')->all() ?? []);
        $leaders = $data['assignee_ids'] ?? $task?->assignees()->pluck('users.id')->all() ?? [];
        $eligibleMembers = [];
        if ($members !== []) {
            if (count($leaders) !== 1) {
                throw ValidationException::withMessages(['team_member_ids' => 'برای تیم انجام دقیقاً یک سرپرست انتخاب کنید.']);
            }
            $leader = User::query()->where('is_active', true)->find((int) $leaders[0]);
            $eligibleMembers = $leader ? $this->descendants($leader)->whereKey($members)->pluck('users.id')->all() : [];
            if ($validateAllMembers && count($eligibleMembers) !== count($members)) {
                throw ValidationException::withMessages(['team_member_ids' => 'اعضای تیم باید از کاربران فعال زیرمجموعه سرپرست باشند.']);
            }
        }
        $items = $data['planning_items'] ?? $task?->planningItems()->get()->toArray() ?? [];
        foreach ($items as $index => $item) {
            if (! empty($item['assignee_user_id']) && ! in_array((int) $item['assignee_user_id'], $eligibleMembers, true)) {
                throw ValidationException::withMessages(["planning_items.$index.assignee_user_id" => 'مسئول این کار باید عضو تیم انجام باشد.']);
            }
        }
    }

    public function mayAcknowledge(User $actor, Task $task): bool
    {
        if (! $actor->is_active || ! $actor->hasPermission(Permissions::TASKS_VIEW)
            || ! in_array($task->status, [TaskStatus::PendingApproval, TaskStatus::ReadyToStart, TaskStatus::InProgress, TaskStatus::PendingCompletionApproval], true)) {
            return false;
        }
        if ($task->transferred_at && $this->isLeader($actor, $task)) {
            return $task->assignee_viewed_at === null;
        }
        $member = $task->teamMembers->firstWhere('id', $actor->id);

        return $member !== null && $member->pivot->viewed_at === null;
    }

    public function acknowledge(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            abort_unless($this->isLeader($actor, $task) && $task->transferred_at || $this->isMember($actor, $task), 403);
            $alreadyViewed = $task->transferred_at && $this->isLeader($actor, $task)
                ? $task->assignee_viewed_at !== null : $task->teamMembers->firstWhere('id', $actor->id)?->pivot->viewed_at !== null;
            if (! $alreadyViewed) {
                Gate::forUser($actor)->authorize('acknowledge', $task);
                if ($task->transferred_at && $this->isLeader($actor, $task)) {
                    DB::table('tasks')->where('id', $task->id)->update(['assignee_viewed_at' => now()]);
                } else {
                    $task->teamMembers()->wherePivotNull('viewed_at')->updateExistingPivot($actor->id, ['viewed_at' => now()]);
                }
                $task->workflowHistory()->create(['actor_id' => $actor->id, 'action' => 'execution_viewed']);
            }

            return app(TaskService::class)->find($task);
        });
    }

    public function transfer(Task $task, User $actor, int $recipientId, ?array $members = null): Task
    {
        return DB::transaction(function () use ($task, $actor, $recipientId, $members): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('transfer', $task);
            $recipient = $this->descendants($actor)->find($recipientId);
            if (! $recipient) {
                throw ValidationException::withMessages(['assignee_user_id' => 'مقصد انتقال باید از کاربران فعال زیرمجموعه شما باشد.']);
            }
            if ($task->assignees()->count() !== 1) {
                throw ValidationException::withMessages(['assignee_user_id' => 'انتقال برای تسک دارای یک مسئول اصلی مجاز است.']);
            }
            $members ??= $this->descendants($recipient)->whereKey($task->teamMembers()->pluck('users.id')->all())->pluck('users.id')->all();
            $this->validate(['assignee_ids' => [$recipientId], 'team_member_ids' => $members, 'planning_items' => []], $task);
            $task->planningItems()->whereNotNull('assignee_user_id')->whereNotIn('assignee_user_id', $members)->update(['assignee_user_id' => null]);
            $task->participantRecords()->where('role', 'assignee')->delete();
            $task->participantRecords()->firstOrCreate(['user_id' => $recipientId, 'role' => 'assignee']);
            $task->participantRecords()->firstOrCreate(['user_id' => $actor->id, 'role' => 'follower']);
            $task->teamMembers()->sync($members);
            DB::table('tasks')->where('id', $task->id)->update(['status' => TaskStatus::ReadyToStart->value, 'transferred_by_user_id' => $actor->id, 'transferred_at' => now(), 'assignee_viewed_at' => null, 'updated_at' => now()]);
            $task->workflowHistory()->create(['actor_id' => $actor->id, 'action' => 'transferred', 'from_status' => $task->status, 'to_status' => TaskStatus::ReadyToStart, 'context' => ['from_user_id' => $actor->id, 'to_user_id' => $recipientId]]);

            return app(TaskService::class)->find($task);
        });
    }

    public function updateTeam(Task $task, User $actor, array $members): Task
    {
        return DB::transaction(function () use ($task, $actor, $members): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('manageTeam', $task);
            $this->validate(['team_member_ids' => $members], $task);
            $task->teamMembers()->sync($members);
            $task->workflowHistory()->create(['actor_id' => $actor->id, 'action' => 'team_updated', 'context' => ['team_member_ids' => $members]]);

            return app(TaskService::class)->find($task);
        });
    }
}
