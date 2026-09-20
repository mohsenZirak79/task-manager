<?php

namespace App\Services;

use App\Enums\TaskParticipantRole;
use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Models\MediaFile;
use App\Models\OrgPosition;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TaskService
{
    public function __construct(private readonly TaskVisibilityService $visibility) {}

    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $sort = $filters['sort'] ?? 'newest';

        $scope = $this->visibility->resolveScope($filters['scope'] ?? null, $filters['submission_type'] ?? null);
        $query = $this->visibility->apply(Task::query()->with($this->summaryRelations()), $actor, $scope)
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
            ->when($filters['assignee_id'] ?? null, fn ($query, int|string $id) => $query->whereHas(
                'participantRecords',
                fn ($query) => $query->where('user_id', $id)->where('role', TaskParticipantRole::Assignee->value),
            ))
            ->when($filters['follower_id'] ?? null, fn ($query, int|string $id) => $query->whereHas(
                'participantRecords',
                fn ($query) => $query->where('user_id', $id)->where('role', TaskParticipantRole::Follower->value),
            ))
            ->when($filters['supervisor_id'] ?? null, fn ($query, int|string $id) => $query->whereHas(
                'participantRecords',
                fn ($query) => $query->where('user_id', $id)->where('role', TaskParticipantRole::Supervisor->value),
            ))
            ->when($filters['tag'] ?? null, fn ($query, string $tag) => $query->whereHas(
                'tags',
                fn ($query) => $query->where('title', 'like', "%{$tag}%"),
            ))
            ->when($filters['due_from'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '<=', $date))
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
                'submission_type' => $data['submission_type'],
            ]);

            $this->syncParticipants($task, $data, true);
            $this->syncTags($task, $data, true);
            $this->syncAttachments($task, $data, $actor, true);
            $this->syncPlanningItems($task, $data, true);

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

            if (array_key_exists('submission_type', $data) && $task->status !== TaskStatus::Draft) {
                throw new HttpException(409, 'نوع تسک فقط در وضعیت پیش‌نویس قابل تغییر است.');
            }

            if (array_key_exists('requester_id', $data)) {
                $requesterId = (int) ($data['requester_id'] ?? $actor->id);
                $this->ensureRequesterCanBeSelected($actor, $requesterId);
                $data['requester_id'] = $requesterId;
            }

            $this->ensureOrganizationParticipants($actor, $data);
            $this->ensureMeetingAssignees($task, $data);

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
            $this->syncTags($task, $data);
            $this->syncAttachments($task, $data, $actor);
            $this->syncPlanningItems($task, $data);

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

    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status !== TaskStatus::Draft) {
                throw new HttpException(409, 'فقط پیش‌نویس تسک قابل حذف است.');
            }
            $task->delete();
        });
    }

    public function approve(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->submission_type !== TaskSubmissionType::Request || $task->status !== TaskStatus::PendingApproval) {
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

            if ($task->submission_type !== TaskSubmissionType::Request || $task->status !== TaskStatus::PendingApproval) {
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

            if ($task->submission_type !== TaskSubmissionType::Request || $task->status !== TaskStatus::PendingApproval) {
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

    public function eligibleUsers(?string $search, User $actor, ?TaskSubmissionType $submissionType = null): array
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

        if ($actor->isSuperAdmin()) {
            $all = $activeUsers->orderBy('first_name')->orderBy('last_name')->get();

            return $this->filterEligibleGroups([
                'assignment_targets' => $all,
                'request_targets' => $all,
                'participants' => $all,
            ], $submissionType);
        }

        $ancestorIds = $this->ancestorPositionIds($actor);
        $descendantIds = $this->descendantPositionIds($actor);
        $positionIds = array_values(array_unique([...$ancestorIds, ...$descendantIds]));
        $load = fn (array $ids): Collection => (clone $activeUsers)
            ->where(function ($query) use ($actor, $ids): void {
                $query->whereKey($actor->id)->orWhereHas('orgPositions', fn ($query) => $query->whereIn('org_positions.id', $ids));
            })
            ->orderBy('first_name')->orderBy('last_name')->get();

        return $this->filterEligibleGroups([
            'assignment_targets' => $load($descendantIds),
            'request_targets' => $load($ancestorIds),
            'participants' => $load($positionIds),
        ], $submissionType);
    }

    private function filterEligibleGroups(array $groups, ?TaskSubmissionType $submissionType): array
    {
        if ($submissionType === TaskSubmissionType::Assignment) {
            $groups['request_targets'] = new Collection;
        } elseif ($submissionType === TaskSubmissionType::Request) {
            $groups['assignment_targets'] = new Collection;
        }

        return $groups;
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
                throw new HttpException(409, 'تغییر وضعیت در این مرحله از گردش کار مجاز نیست.');
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
                throw new HttpException(409, 'درصد پیشرفت فقط برای تسک در حال انجام قابل ثبت است.');
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

        $participantIds = $task->participantRecords()->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->ensureSelectedUsersRemainValid($task, $actor, $participantIds);

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

        $submissionType = $this->resolveSubmissionType($task, $actor, $assigneeIds);
        $this->validateCompleteSubmission($task, $submissionType);
        $newStatus = $submissionType === TaskSubmissionType::Request
            ? TaskStatus::PendingApproval
            : TaskStatus::InProgress;

        $task->update([
            'submission_type' => $submissionType,
            'status' => $newStatus,
        ]);
        $this->recordHistory($task, $actor, 'submitted', TaskStatus::Draft, $newStatus);
    }

    private function resolveSubmissionType(Task $task, User $actor, array $assigneeIds): TaskSubmissionType
    {
        if ($actor->isSuperAdmin()) {
            return $task->submission_type ?? TaskSubmissionType::Assignment;
        }

        $actorPositions = $actor->orgPositions()->get(['org_positions.id', 'parent_id', 'request_up_levels', 'assignment_down_levels']);
        if ($actorPositions->isEmpty()) {
            throw new HttpException(403, 'کاربر ایجادکننده جایگاه سازمانی ندارد.');
        }

        $targets = User::query()->whereKey($assigneeIds)->with('orgPositions:id,parent_id')->get();
        if ($targets->count() !== count(array_unique($assigneeIds)) || $targets->contains(fn (User $user) => $user->orgPositions->isEmpty())) {
            throw new HttpException(422, 'همه مسئولان انجام باید جایگاه سازمانی داشته باشند.');
        }

        $parents = $this->positionParents();
        $directions = $targets->map(function (User $target) use ($actorPositions, $parents): array {
            $below = false;
            $above = false;
            foreach ($actorPositions as $actorPosition) {
                foreach ($target->orgPositions as $targetPosition) {
                    $below = $below || $this->isWithinLevelLimit(
                        $actorPosition->id, $targetPosition->id, $parents, $actorPosition->assignment_down_levels,
                    );
                    $above = $above || $this->isWithinLevelLimit(
                        $targetPosition->id, $actorPosition->id, $parents, $actorPosition->request_up_levels,
                    );
                }
            }

            return ['below' => $below, 'above' => $above];
        });

        $allBelow = $directions->every(fn (array $direction): bool => $direction['below']);
        $allAbove = $directions->every(fn (array $direction): bool => $direction['above']);

        $actual = match (true) {
            $allBelow && ! $allAbove => TaskSubmissionType::Assignment,
            $allAbove && ! $allBelow => TaskSubmissionType::Request,
            $allBelow && $allAbove => $task->submission_type ?? TaskSubmissionType::Assignment,
            default => null,
        };

        if ($actual === null) {
            throw new HttpException(403, 'مسئولان باید همگی در محدوده مجازِ بالاتر یا همگی در محدوده مجازِ پایین‌تر یکی از جایگاه‌های شما باشند.');
        }

        if ($task->submission_type !== null && $task->submission_type !== $actual) {
            throw ValidationException::withMessages([
                'submission_type' => 'نوع انتخاب‌شده با جهت سازمانی مسئولان سازگار نیست.',
            ]);
        }

        return $task->submission_type ?? $actual;
    }

    private function validateCompleteSubmission(Task $task, TaskSubmissionType $submissionType): void
    {
        $errors = [];
        if (blank($task->title)) {
            $errors['title'] = 'عنوان هنگام ارسال اجباری است.';
        }
        if (blank($task->short_description)) {
            $errors['short_description'] = 'توضیحات مختصر هنگام ارسال اجباری است.';
        }

        if ($submissionType === TaskSubmissionType::Request) {
            if (! $task->tags()->exists()) {
                $errors['tags'] = 'برای ارسال درخواست حداقل یک تگ لازم است.';
            }
            foreach ([
                'follower_ids' => TaskParticipantRole::Follower,
                'supervisor_ids' => TaskParticipantRole::Supervisor,
            ] as $field => $role) {
                if (! $task->participantRecords()->where('role', $role->value)->exists()) {
                    $errors[$field] = "برای ارسال درخواست حداقل یک {$role->value} لازم است.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ensureSelectedUsersRemainValid(Task $task, User $actor, array $participantIds): void
    {
        $selectedIds = collect($participantIds)
            ->push($task->requester_id, $task->financial_provider_user_id, $task->equipment_provider_user_id)
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $activeIds = User::query()->whereKey($selectedIds)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (array_diff($selectedIds, $activeIds) !== []) {
            throw ValidationException::withMessages(['users' => 'تمام کاربران انتخاب‌شده باید فعال باشند.']);
        }

        if (! $actor->isSuperAdmin() && array_diff($selectedIds, $this->allowedUserIds($actor)) !== []) {
            throw new HttpException(403, 'یکی از کاربران انتخاب‌شده خارج از محدوده سازمانی مجاز است.');
        }
    }

    private function isWithinLevelLimit(int $ancestorId, int $descendantId, array $parents, ?int $limit): bool
    {
        $distance = $this->positionDistance($ancestorId, $descendantId, $parents);

        return $distance !== null && ($limit === null || $distance <= $limit);
    }

    private function positionDistance(int $ancestorId, int $descendantId, array $parents): ?int
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
        if (! $actor->isSuperAdmin() && $requesterId !== $actor->id) {
            throw new HttpException(403, 'ثبت تسک به درخواست کاربر دیگر مجاز نیست.');
        }
    }

    private function ensureOrganizationParticipants(User $actor, array $data): void
    {
        if ($actor->isSuperAdmin()) {
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
        $positionIds = $this->relatedPositionIds($actor);

        return User::query()->where(function ($query) use ($actor, $positionIds): void {
            $query->whereKey($actor->id)
                ->orWhereHas('orgPositions', fn ($query) => $query->whereIn('org_positions.id', $positionIds));
        })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function relatedPositionIds(User $actor): array
    {
        return array_values(array_unique([...$this->ancestorPositionIds($actor), ...$this->descendantPositionIds($actor)]));
    }

    private function ancestorPositionIds(User $actor): array
    {
        $positions = $actor->orgPositions()->get(['org_positions.id', 'request_up_levels']);
        $parents = $this->positionParents();
        $ids = [];
        foreach ($positions as $position) {
            $current = $parents[$position->id] ?? null;
            $distance = 1;
            while ($current !== null && ($position->request_up_levels === null || $distance <= $position->request_up_levels)) {
                $ids[] = (int) $current;
                $current = $parents[$current] ?? null;
                $distance++;
            }
        }

        return array_values(array_unique($ids));
    }

    private function descendantPositionIds(User $actor): array
    {
        $positions = $actor->orgPositions()->get(['org_positions.id', 'assignment_down_levels']);
        $parents = $this->positionParents();
        $ids = [];
        foreach ($positions as $position) {
            $ids[] = (int) $position->id;
            foreach (array_keys($parents) as $positionId) {
                $distance = $this->positionDistance((int) $position->id, (int) $positionId, $parents);
                if ($distance !== null && ($position->assignment_down_levels === null || $distance <= $position->assignment_down_levels)) {
                    $ids[] = (int) $positionId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, int|null> */
    private function positionParents(): array
    {
        return OrgPosition::query()->pluck('parent_id', 'id')->map(fn ($id) => $id === null ? null : (int) $id)->all();
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

    private function syncTags(Task $task, array $data, bool $creating = false): void
    {
        if (! $creating && ! array_key_exists('tags', $data)) {
            return;
        }

        $tagIds = collect($data['tags'] ?? [])
            ->map(fn (string $title): string => trim($title))
            ->filter()
            ->unique(fn (string $title): string => mb_strtolower($title))
            ->map(function (string $title): int {
                $tag = Tag::query()->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->first();

                return ($tag ?? Tag::query()->firstOrCreate(['title' => $title]))->id;
            })
            ->all();

        $task->tags()->sync($tagIds);
    }

    private function syncAttachments(Task $task, array $data, User $actor, bool $creating = false): void
    {
        if (! $creating && ! array_key_exists('attachment_file_ids', $data)) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $data['attachment_file_ids'] ?? [])));
        $files = MediaFile::query()->whereKey($ids)->where('category', 'attachment')->get();
        if ($files->count() !== count($ids)) {
            throw ValidationException::withMessages(['attachment_file_ids' => 'یکی از فایل‌های پیوست معتبر نیست.']);
        }

        $existingIds = $task->attachments()->pluck('media_files.id')->map(fn ($id) => (int) $id)->all();
        $unauthorized = $files->contains(fn (MediaFile $file): bool => ! $actor->isSuperAdmin()
            && $file->uploaded_by !== $actor->id
            && ! in_array($file->id, $existingIds, true));
        if ($unauthorized) {
            throw new HttpException(403, 'اتصال فایل متعلق به کاربر دیگر مجاز نیست.');
        }

        $task->attachments()->sync($ids);
    }

    private function syncPlanningItems(Task $task, array $data, bool $creating = false): void
    {
        if (! $creating && ! array_key_exists('planning_items', $data)) {
            return;
        }

        $task->planningItems()->delete();
        foreach (array_values($data['planning_items'] ?? []) as $index => $item) {
            $task->planningItems()->create([
                'title' => $item['title'],
                'weight' => $item['weight'],
                'progress_percentage' => $item['progress_percentage'] ?? 0,
                'sort_order' => $item['sort_order'] ?? $index,
            ]);
        }
    }

    private function ensureMeetingAssignees(Task $task, array $data): void
    {
        if (! array_key_exists('assignee_ids', $data)) {
            return;
        }

        $meeting = $task->meetingResolution()->with('meeting')->first()?->meeting;
        if (! $meeting) {
            return;
        }

        $memberIds = $meeting->attendees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $invalidIds = array_diff(array_map('intval', $data['assignee_ids']), $memberIds);
        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'assignee_ids' => 'همه مسئولان تسک مصوبه باید از اعضای همان جلسه باشند.',
            ]);
        }
    }

    private function taskAttributes(array $data): array
    {
        return Arr::except($data, [
            'assignee_ids', 'follower_ids', 'supervisor_ids', 'tags',
            'attachment_file_ids', 'planning_items', 'submit',
        ]);
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
            'participantRecords', 'tags', 'attachments', 'planningItems',
            'financialProvider', 'equipmentProvider', 'meetingResolution.meeting',
        ];
    }
}
