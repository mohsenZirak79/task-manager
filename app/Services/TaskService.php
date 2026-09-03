<?php

namespace App\Services;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TaskService
{
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $sort = $filters['sort'] ?? 'newest';

        $query = Task::query()
            ->with($this->summaryRelations())
            ->when(! $actor->is_admin, function ($query) use ($actor): void {
                $query->where(function ($query) use ($actor): void {
                    $query->where('created_by', $actor->id)
                        ->orWhere('requester_id', $actor->id)
                        ->orWhere('financial_provider_user_id', $actor->id)
                        ->orWhere('equipment_provider_user_id', $actor->id)
                        ->orWhereHas('participantRecords', fn ($query) => $query->where('user_id', $actor->id));
                });
            })
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
                        ->orWhere('request_description', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(
                $filters['submission_type'] ?? null,
                fn ($query, string $submissionType) => $query->where('submission_type', $submissionType),
            )
            ->when($filters['user_id'] ?? null, function ($query, int|string $userId): void {
                $query->where(function ($query) use ($userId): void {
                    $query->where('created_by', $userId)
                        ->orWhere('requester_id', $userId)
                        ->orWhere('financial_provider_user_id', $userId)
                        ->orWhere('equipment_provider_user_id', $userId)
                        ->orWhereHas('participantRecords', fn ($query) => $query->where('user_id', $userId));
                });
            })
            ->when($filters['created_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['created_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date));

        match ($sort) {
            'oldest' => $query->oldest(),
            'longest_duration' => $query->orderByDesc('duration_minutes')->latest('id'),
            'shortest_duration' => $query->orderBy('duration_minutes')->latest('id'),
            'highest_progress' => $query->orderByDesc('progress_percentage')->latest('id'),
            'lowest_progress' => $query->orderBy('progress_percentage')->latest('id'),
            default => $query->latest(),
        };

        return $query->paginate($perPage);
    }

    public function create(array $data, User $actor): Task
    {
        return DB::transaction(function () use ($data, $actor): Task {
            $submit = (bool) ($data['submit'] ?? false);
            $requesterId = (int) ($data['requester_id'] ?? $actor->id);
            $this->ensureRequesterCanBeSelected($actor, $requesterId);
            $this->ensureOrganizationParticipants($actor, $data);

            $task = Task::query()->create([
                ...$this->taskAttributes($data),
                'requester_id' => $requesterId,
                'created_by' => $actor->id,
                'status' => TaskStatus::Draft,
                'submission_type' => null,
            ]);

            $this->syncParticipants($task, $data, true);

            if ($submit) {
                $this->submit($task, $actor);
            }

            return $this->loadTask($task);
        });
    }

    public function update(Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($task, $data, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (array_key_exists('requester_id', $data)) {
                $requesterId = (int) ($data['requester_id'] ?? $actor->id);
                $this->ensureRequesterCanBeSelected($actor, $requesterId);
                $data['requester_id'] = $requesterId;
            }

            $this->ensureOrganizationParticipants($actor, $data);

            if (in_array($task->status, [TaskStatus::RevisionRequested, TaskStatus::Rejected], true)) {
                $from = $task->status;
                $task->update([
                    'status' => TaskStatus::Draft,
                    'rejection_reason' => null,
                ]);
                $this->recordHistory($task, $actor, 'revision_edit_started', $from, TaskStatus::Draft);
            }

            $task->update($this->taskAttributes($data));
            $this->syncParticipants($task, $data);

            if ((bool) ($data['submit'] ?? false)) {
                $this->submit($task, $actor);
            }

            return $this->loadTask($task);
        });
    }

    public function find(Task $task): Task
    {
        return $this->loadTask($task, true);
    }

    public function approve(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== TaskStatus::PendingApproval) {
                throw new HttpException(409, 'این درخواست دیگر در انتظار تأیید نیست.');
            }

            $from = $task->status;
            $task->update(['status' => TaskStatus::InProgress, 'rejection_reason' => null]);
            $this->recordHistory($task, $actor, 'approved', $from, TaskStatus::InProgress);

            return $this->loadTask($task);
        });
    }

    public function reject(Task $task, User $actor, string $reason): Task
    {
        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== TaskStatus::PendingApproval) {
                throw new HttpException(409, 'این درخواست دیگر در انتظار تأیید نیست.');
            }

            $from = $task->status;
            $task->update(['status' => TaskStatus::Rejected, 'rejection_reason' => $reason]);
            $this->recordHistory($task, $actor, 'rejected', $from, TaskStatus::Rejected, reason: $reason);

            return $this->loadTask($task);
        });
    }

    public function requestRevision(Task $task, User $actor, string $reason): Task
    {
        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== TaskStatus::PendingApproval) {
                throw new HttpException(409, 'فقط درخواست در انتظار تأیید قابل اصلاح است.');
            }

            $from = $task->status;
            $task->update([
                'status' => TaskStatus::RevisionRequested,
                'rejection_reason' => $reason,
            ]);
            $this->recordHistory($task, $actor, 'revision_requested', $from, TaskStatus::RevisionRequested, reason: $reason);

            return $this->loadTask($task);
        });
    }

    public function eligibleUsers(?string $search, User $actor): array
    {
        $activeUsers = User::query()->where('is_active', true);
        if ($search) {
            $activeUsers->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('org_code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        if ($actor->is_admin) {
            $all = $activeUsers->orderBy('first_name')->orderBy('last_name')->get();

            return [
                'assignment_targets' => $all,
                'request_targets' => $all,
                'participants' => $all,
            ];
        }

        $actorRole = $actor->role()->first(['id', 'request_up_levels', 'assignment_down_levels']);
        $ancestorIds = $this->ancestorRoleIds($actor, $actorRole?->request_up_levels);
        $descendantIds = $this->descendantRoleIds($actor, $actorRole?->assignment_down_levels);
        $roleIds = array_values(array_unique([...$ancestorIds, ...$descendantIds]));
        $load = fn (array $ids): Collection => (clone $activeUsers)
            ->where(function ($query) use ($actor, $ids): void {
                $query->whereKey($actor->id)->orWhereHas('role', fn ($query) => $query->whereIn('id', $ids));
            })
            ->orderBy('first_name')->orderBy('last_name')->get();

        return [
            'assignment_targets' => $load($descendantIds),
            'request_targets' => $load($ancestorIds),
            'participants' => $load($roleIds),
        ];
    }

    public function changeStatus(Task $task, User $actor, TaskStatus $status): Task
    {
        return DB::transaction(function () use ($task, $actor, $status): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $allowed = match ($task->status) {
                TaskStatus::InProgress => [TaskStatus::Completed, TaskStatus::NotCompleted],
                TaskStatus::NotCompleted => [TaskStatus::InProgress],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw new HttpException(422, 'تغییر وضعیت در این مرحله از گردش کار مجاز نیست.');
            }

            $from = $task->status;
            $task->update(['status' => $status]);
            $this->recordHistory($task, $actor, 'status_changed', $from, $status);

            return $this->loadTask($task);
        });
    }

    public function updateProgress(Task $task, User $actor, int $progress): Task
    {
        return DB::transaction(function () use ($task, $actor, $progress): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== TaskStatus::InProgress) {
                throw new HttpException(422, 'درصد پیشرفت فقط برای تسک در حال انجام قابل ثبت است.');
            }

            $oldProgress = $task->progress_percentage;
            $task->update(['progress_percentage' => $progress]);
            $this->recordHistory(
                $task,
                $actor,
                'progress_updated',
                oldProgress: $oldProgress,
                newProgress: $progress,
            );

            return $this->loadTask($task);
        });
    }

    private function submit(Task $task, User $actor): void
    {
        if ($task->status !== TaskStatus::Draft) {
            throw new HttpException(409, 'فقط پیش‌نویس قابل ارسال است.');
        }

        $assigneeIds = $task->participantRecords()
            ->where('role', TaskParticipantRole::Assignee->value)
            ->pluck('user_id')
            ->all();

        if ($assigneeIds === []) {
            throw new HttpException(422, 'برای ارسال تسک حداقل یک مسئول انجام لازم است.');
        }

        if ($task->duration_minutes === null || $task->duration_minutes < 1) {
            throw new HttpException(422, 'مدت‌زمان هنگام ارسال اجباری و باید معتبر باشد.');
        }

        if ($task->due_date === null) {
            throw new HttpException(422, 'تاریخ اتمام هنگام ارسال اجباری است.');
        }

        if ($task->due_date?->isBefore(today())) {
            throw new HttpException(422, 'تاریخ اتمام در زمان ارسال نمی‌تواند گذشته باشد.');
        }

        $submissionType = $this->determineSubmissionType($actor, $assigneeIds);
        $newStatus = $submissionType === TaskSubmissionType::Request
            ? TaskStatus::PendingApproval
            : TaskStatus::InProgress;

        $task->update([
            'submission_type' => $submissionType,
            'status' => $newStatus,
        ]);
        $this->recordHistory($task, $actor, 'submitted', TaskStatus::Draft, $newStatus);
    }

    private function determineSubmissionType(User $actor, array $assigneeIds): TaskSubmissionType
    {
        if ($actor->is_admin) {
            return TaskSubmissionType::Assignment;
        }

        $actorRole = $actor->role()->first(['id', 'request_up_levels', 'assignment_down_levels']);
        if (! $actorRole) {
            throw new HttpException(403, 'کاربر ایجادکننده جایگاه سازمانی ندارد.');
        }

        $targetRoleIds = Role::query()
            ->whereIn('user_id', $assigneeIds)
            ->pluck('id', 'user_id');

        if ($targetRoleIds->count() !== count(array_unique($assigneeIds))) {
            throw new HttpException(422, 'همه مسئولان انجام باید جایگاه سازمانی داشته باشند.');
        }

        $parents = Role::query()->pluck('parent_id', 'id')->all();
        $allBelow = $targetRoleIds->every(fn (int $targetRoleId) => $this->isWithinLevelLimit(
            $actorRole->id,
            $targetRoleId,
            $parents,
            $actorRole->assignment_down_levels,
        ));
        $allAbove = $targetRoleIds->every(fn (int $targetRoleId) => $this->isWithinLevelLimit(
            $targetRoleId,
            $actorRole->id,
            $parents,
            $actorRole->request_up_levels,
        ));

        if ($allBelow) {
            return TaskSubmissionType::Assignment;
        }

        if ($allAbove) {
            return TaskSubmissionType::Request;
        }

        throw new HttpException(403, 'ارسال فقط در محدوده سطوح مجازِ بالاتر یا پایین‌تر این نقش امکان‌پذیر است.');
    }

    private function isWithinLevelLimit(int $ancestorId, int $descendantId, array $parents, ?int $limit): bool
    {
        $distance = $this->roleDistance($ancestorId, $descendantId, $parents);

        return $distance !== null && ($limit === null || $distance <= $limit);
    }

    private function roleDistance(int $ancestorId, int $descendantId, array $parents): ?int
    {
        if ($ancestorId === $descendantId) {
            return 0;
        }

        $currentId = $parents[$descendantId] ?? null;
        $visited = [];
        $distance = 1;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            if ((int) $currentId === $ancestorId) {
                return $distance;
            }

            $visited[$currentId] = true;
            $currentId = $parents[$currentId] ?? null;
            $distance++;
        }

        return null;
    }

    private function ensureRequesterCanBeSelected(User $actor, int $requesterId): void
    {
        if (! $actor->is_admin && $requesterId !== $actor->id) {
            throw new HttpException(403, 'ثبت تسک به درخواست کاربر دیگر مجاز نیست.');
        }
    }

    private function ensureOrganizationParticipants(User $actor, array $data): void
    {
        if ($actor->is_admin) {
            return;
        }

        $allowed = $this->allowedUserIds($actor);
        $fields = [
            'assignee_ids', 'follower_ids', 'supervisor_ids',
            'financial_provider_user_id', 'equipment_provider_user_id',
        ];

        foreach ($fields as $field) {
            $values = is_array($data[$field] ?? null) ? $data[$field] : [$data[$field] ?? null];
            foreach (array_filter($values, fn ($value) => $value !== null) as $userId) {
                if (! in_array((int) $userId, $allowed, true)) {
                    throw new HttpException(403, 'انتخاب کاربر خارج از شاخه سازمانی مجاز نیست.');
                }
            }
        }
    }

    private function allowedUserIds(User $actor): array
    {
        return User::query()->whereHas('role', fn ($query) => $query->whereIn('id', $this->relatedRoleIds($actor)))
            ->orWhere('id', $actor->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function relatedRoleIds(User $actor): array
    {
        $actorRoleId = $actor->role()->value('id');
        if (! $actorRoleId) {
            return [];
        }

        $parents = Role::query()->pluck('parent_id', 'id')->map(fn ($id) => $id === null ? null : (int) $id)->all();
        $ids = [(int) $actorRoleId];
        foreach ($parents as $roleId => $parentId) {
            $current = $parentId;
            while ($current !== null) {
                if ((int) $current === (int) $actorRoleId) {
                    $ids[] = (int) $roleId;
                    break;
                }
                $current = $parents[$current] ?? null;
            }
        }

        $current = $parents[$actorRoleId] ?? null;
        while ($current !== null) {
            $ids[] = (int) $current;
            $current = $parents[$current] ?? null;
        }

        return array_values(array_unique($ids));
    }

    private function ancestorRoleIds(User $actor, ?int $limit = null): array
    {
        $actorRoleId = $actor->role()->value('id');
        if (! $actorRoleId) {
            return [];
        }

        $parents = Role::query()->pluck('parent_id', 'id')->all();
        $ids = [];
        $current = $parents[$actorRoleId] ?? null;
        while ($current !== null && ($limit === null || count($ids) < $limit)) {
            $ids[] = (int) $current;
            $current = $parents[$current] ?? null;
        }

        return $ids;
    }

    private function descendantRoleIds(User $actor, ?int $limit = null): array
    {
        $actorRoleId = $actor->role()->value('id');
        if (! $actorRoleId) {
            return [];
        }

        $parents = Role::query()->pluck('parent_id', 'id')->all();
        $ids = [(int) $actorRoleId];

        foreach ($parents as $roleId => $parentId) {
            $distance = $this->roleDistance((int) $actorRoleId, (int) $roleId, $parents);
            if ($distance !== null && ($limit === null || $distance <= $limit)) {
                $ids[] = (int) $roleId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function syncParticipants(Task $task, array $data, bool $creating = false): void
    {
        $participantFields = [
            'assignee_ids' => TaskParticipantRole::Assignee,
            'follower_ids' => TaskParticipantRole::Follower,
            'supervisor_ids' => TaskParticipantRole::Supervisor,
        ];

        foreach ($participantFields as $field => $role) {
            if (! $creating && ! array_key_exists($field, $data)) {
                continue;
            }

            $task->participantRecords()->where('role', $role->value)->delete();
            foreach (array_unique($data[$field] ?? []) as $userId) {
                $task->participantRecords()->create(['user_id' => $userId, 'role' => $role]);
            }
        }
    }

    private function taskAttributes(array $data): array
    {
        return Arr::except($data, ['assignee_ids', 'follower_ids', 'supervisor_ids', 'submit']);
    }

    private function recordHistory(
        Task $task,
        User $actor,
        string $action,
        ?TaskStatus $fromStatus = null,
        ?TaskStatus $toStatus = null,
        ?int $oldProgress = null,
        ?int $newProgress = null,
        ?string $reason = null,
    ): void {
        $task->workflowHistory()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'old_progress' => $oldProgress,
            'new_progress' => $newProgress,
            'reason' => $reason,
        ]);
    }

    private function loadTask(Task $task, bool $withHistory = false): Task
    {
        $relations = $this->summaryRelations();
        if ($withHistory) {
            $relations[] = 'workflowHistory.actor';
        }

        return $task->fresh($relations);
    }

    /** @return array<int, string> */
    private function summaryRelations(): array
    {
        return [
            'requester', 'creator', 'assignees', 'followers', 'supervisors',
            'financialProvider', 'equipmentProvider',
        ];
    }
}
