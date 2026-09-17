<?php

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MeetingService
{
    public function __construct(private readonly TaskService $taskService) {}

    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        return Meeting::query()
            ->with(['chairman', 'secretary'])
            ->withCount(['attendees', 'agendaItems', 'resolutions'])
            ->when(! $this->hasAdministrativeAccess($actor), function ($query) use ($actor): void {
                $query->where(function ($query) use ($actor): void {
                    $query->where('created_by', $actor->id)
                        ->orWhere('chairman_user_id', $actor->id)
                        ->orWhere('secretary_user_id', $actor->id)
                        ->orWhereHas('attendees', fn ($query) => $query->whereKey($actor->id));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['from_date'] ?? null, fn ($query, string $date) => $query->whereDate('meeting_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, string $date) => $query->whereDate('meeting_date', '<=', $date))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('meeting_date')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, User $actor): Meeting
    {
        return DB::transaction(function () use ($data, $actor): Meeting {
            $meeting = Meeting::query()->create([
                ...$this->meetingAttributes($data),
                'status' => MeetingStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $this->syncAttendees($meeting, $data, true);
            $this->syncAgendaItems($meeting, $data);

            if ((bool) ($data['submit'] ?? false)) {
                $this->markSubmitted($meeting);
            }

            return $this->loadMeeting($meeting);
        });
    }

    public function update(Meeting $meeting, array $data): Meeting
    {
        return DB::transaction(function () use ($meeting, $data): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->ensureEditable($meeting);
            $meeting->update($this->meetingAttributes($data));
            $this->syncAttendees($meeting, $data);
            $this->syncAgendaItems($meeting, $data);

            if ((bool) ($data['submit'] ?? false)) {
                $this->markSubmitted($meeting);
            }

            return $this->loadMeeting($meeting);
        });
    }

    public function find(Meeting $meeting): Meeting
    {
        return $this->loadMeeting($meeting);
    }

    public function submit(Meeting $meeting): Meeting
    {
        return DB::transaction(function () use ($meeting): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->markSubmitted($meeting);

            return $this->loadMeeting($meeting);
        });
    }

    public function complete(Meeting $meeting): Meeting
    {
        return DB::transaction(function () use ($meeting): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($meeting->status !== MeetingStatus::Scheduled) {
                throw new HttpException(409, 'فقط جلسه زمان‌بندی‌شده قابل اتمام است.');
            }
            if (! $meeting->resolutions()->exists()) {
                throw ValidationException::withMessages([
                    'resolutions' => 'برای اتمام جلسه حداقل یک مصوبه لازم است.',
                ]);
            }

            $meeting->update(['status' => MeetingStatus::Completed, 'completed_at' => now()]);

            return $this->loadMeeting($meeting);
        });
    }

    public function delete(Meeting $meeting): void
    {
        DB::transaction(function () use ($meeting): void {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($meeting->status === MeetingStatus::Completed) {
                throw new HttpException(409, 'جلسه تکمیل‌شده قابل حذف نیست.');
            }
            $meeting->delete();
        });
    }

    public function createResolution(Meeting $meeting, array $data, User $actor): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $data, $actor): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->ensureScheduled($meeting);
            $this->ensureAgendaBelongsToMeeting($meeting, $data['agenda_item_id'] ?? null);
            $this->authorizeLinkedTask($data['task_id'] ?? null, $actor);

            $resolution = $meeting->resolutions()->create([
                ...Arr::only($data, ['agenda_item_id', 'task_id', 'title', 'description', 'sort_order']),
                'created_by' => $actor->id,
            ]);

            return $this->loadResolution($resolution);
        });
    }

    public function updateResolution(Meeting $meeting, MeetingResolution $resolution, array $data, User $actor): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $resolution, $data, $actor): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureScheduled($meeting);
            if (array_key_exists('agenda_item_id', $data)) {
                $this->ensureAgendaBelongsToMeeting($meeting, $data['agenda_item_id']);
            }
            if (array_key_exists('task_id', $data)) {
                $this->authorizeLinkedTask($data['task_id'], $actor);
            }
            $resolution->update(Arr::only($data, ['agenda_item_id', 'task_id', 'title', 'description', 'sort_order']));

            return $this->loadResolution($resolution);
        });
    }

    public function deleteResolution(Meeting $meeting, MeetingResolution $resolution): void
    {
        DB::transaction(function () use ($meeting, $resolution): void {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureScheduled($meeting);
            $resolution->delete();
        });
    }

    public function createTask(Meeting $meeting, MeetingResolution $resolution, array $data, User $actor): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $resolution, $data, $actor): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $resolution = MeetingResolution::query()->lockForUpdate()->findOrFail($resolution->id);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureScheduled($meeting);
            if ($resolution->task_id !== null) {
                throw new HttpException(409, 'برای این مصوبه قبلاً تسک ثبت شده است.');
            }

            Gate::forUser($actor)->authorize('create', Task::class);
            $task = $this->taskService->create([
                ...$data,
                'title' => $data['title'] ?? $resolution->title,
                'short_description' => ($data['short_description'] ?? null) ?: Str::limit($resolution->title, 100, ''),
            ], $actor);
            $resolution->update(['task_id' => $task->id]);

            return $this->loadResolution($resolution);
        });
    }

    public function linkTask(Meeting $meeting, MeetingResolution $resolution, Task $task, User $actor): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $resolution, $task, $actor): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $resolution = MeetingResolution::query()->lockForUpdate()->findOrFail($resolution->id);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureScheduled($meeting);
            if ($resolution->task_id !== null) {
                throw new HttpException(409, 'برای این مصوبه قبلاً تسک ثبت شده است.');
            }
            Gate::forUser($actor)->authorize('view', $task);
            if (MeetingResolution::query()->where('task_id', $task->id)->exists()) {
                throw new HttpException(409, 'این تسک قبلاً به مصوبه دیگری متصل شده است.');
            }
            $resolution->update(['task_id' => $task->id]);

            return $this->loadResolution($resolution);
        });
    }

    public function ensureResolutionBelongsToMeeting(Meeting $meeting, MeetingResolution $resolution): void
    {
        if ($resolution->meeting_id !== $meeting->id) {
            abort(404);
        }
    }

    private function markSubmitted(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Draft) {
            throw new HttpException(409, 'فقط پیش‌نویس جلسه قابل ارسال است.');
        }
        $this->validateSubmission($meeting);
        $meeting->update(['status' => MeetingStatus::Scheduled, 'submitted_at' => now()]);
    }

    private function validateSubmission(Meeting $meeting): void
    {
        $messages = [];
        foreach ([
            'title' => 'عنوان جلسه', 'location' => 'مکان جلسه', 'meeting_date' => 'تاریخ جلسه',
            'start_time' => 'ساعت جلسه', 'chairman_user_id' => 'رئیس جلسه', 'secretary_user_id' => 'دبیر جلسه',
        ] as $field => $label) {
            if ($meeting->{$field} === null || $meeting->{$field} === '') {
                $messages[$field] = "{$label} هنگام ارسال اجباری است.";
            }
        }
        $participantIds = DB::table('meeting_attendees')->where('meeting_id', $meeting->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $participantIds = array_values(array_unique(array_filter([...$participantIds, $meeting->chairman_user_id, $meeting->secretary_user_id])));
        if (User::query()->whereKey($participantIds)->where('is_active', true)->count() !== count($participantIds)) {
            $messages['attendees'] = 'رئیس، دبیر و حاضرین جلسه باید کاربران فعال و حذف‌نشده باشند.';
        }

        if (! $meeting->agendaItems()->exists()) {
            $messages['agenda_items'] = 'حداقل یک دستور جلسه هنگام ارسال اجباری است.';
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function syncAttendees(Meeting $meeting, array $data, bool $creating = false): void
    {
        if (! $creating && ! array_key_exists('attendee_user_ids', $data)
            && ! array_key_exists('chairman_user_id', $data) && ! array_key_exists('secretary_user_id', $data)) {
            return;
        }
        $ids = array_key_exists('attendee_user_ids', $data)
            ? $data['attendee_user_ids']
            : ($creating ? [] : $meeting->attendees()->pluck('users.id')->all());
        foreach ([$meeting->chairman_user_id, $meeting->secretary_user_id] as $requiredId) {
            if ($requiredId !== null) {
                $ids[] = $requiredId;
            }
        }
        $meeting->attendees()->sync(array_values(array_unique(array_map('intval', $ids))));
    }

    private function syncAgendaItems(Meeting $meeting, array $data): void
    {
        if (! array_key_exists('agenda_items', $data)) {
            return;
        }
        $items = collect($data['agenda_items'])->sortBy('sort_order')->values();
        $ids = $items->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($ids !== [] && $meeting->agendaItems()->whereKey($ids)->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['agenda_items' => 'حداقل یک دستور جلسه متعلق به این جلسه نیست.']);
        }
        foreach ($items as $item) {
            $attributes = Arr::only($item, ['title', 'description', 'sort_order']);
            isset($item['id'])
                ? $meeting->agendaItems()->whereKey($item['id'])->firstOrFail()->update($attributes)
                : $ids[] = $meeting->agendaItems()->create($attributes)->id;
        }
        $query = $meeting->agendaItems();
        $ids === [] ? $query->delete() : $query->whereNotIn('id', $ids)->delete();
    }

    private function ensureAgendaBelongsToMeeting(Meeting $meeting, mixed $agendaItemId): void
    {
        if ($agendaItemId !== null && ! $meeting->agendaItems()->whereKey($agendaItemId)->exists()) {
            throw ValidationException::withMessages(['agenda_item_id' => 'دستور جلسه باید متعلق به همین جلسه باشد.']);
        }
    }

    private function authorizeLinkedTask(mixed $taskId, User $actor): void
    {
        if ($taskId === null) {
            return;
        }

        Gate::forUser($actor)->authorize('view', Task::query()->findOrFail($taskId));
    }

    private function ensureEditable(Meeting $meeting): void
    {
        if (! in_array($meeting->status, [MeetingStatus::Draft, MeetingStatus::Scheduled], true)) {
            throw new HttpException(409, 'جلسه تکمیل یا لغوشده قابل ویرایش نیست.');
        }
    }

    private function ensureScheduled(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Scheduled) {
            throw new HttpException(409, 'مدیریت مصوبات فقط برای جلسه زمان‌بندی‌شده مجاز است.');
        }
    }

    private function meetingAttributes(array $data): array
    {
        return Arr::only($data, ['title', 'location', 'meeting_date', 'start_time', 'chairman_user_id', 'secretary_user_id']);
    }

    private function hasAdministrativeAccess(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasAccessRole(AccessRoles::ADMIN);
    }

    private function loadMeeting(Meeting $meeting): Meeting
    {
        return $meeting->fresh([
            'chairman', 'secretary', 'creator', 'attendees', 'agendaItems',
            'resolutions.agendaItem', 'resolutions.creator', 'resolutions.task.assignees',
        ]);
    }

    private function loadResolution(MeetingResolution $resolution): MeetingResolution
    {
        return $resolution->fresh(['agendaItem', 'creator', 'task.requester', 'task.creator', 'task.assignees', 'task.followers', 'task.supervisors', 'task.financialProvider', 'task.equipmentProvider', 'task.meetingResolution.meeting']);
    }
}
