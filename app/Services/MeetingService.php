<?php

namespace App\Services;

use App\Enums\MeetingResolutionType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MeetingService
{
    public function __construct(private readonly TaskService $taskService, private readonly MeetingParticipantService $participants) {}

    public function visibleQuery(User $actor): Builder
    {
        return Meeting::query()
            ->when(! $this->hasAdministrativeAccess($actor), function ($query) use ($actor): void {
                $query->where(function ($query) use ($actor): void {
                    $query->where('created_by', $actor->id)
                        ->orWhere('chairman_user_id', $actor->id)
                        ->orWhere('secretary_user_id', $actor->id)
                        ->orWhereHas('attendees', fn ($query) => $query->whereKey($actor->id));
                });
            });
    }

    /** Same signing/acknowledgment rules as MeetingPolicy, restricted to visible records. */
    public function actionRequiredQuery(User $actor): Builder
    {
        $query = $this->visibleQuery($actor);
        if (! $actor->is_active || ! Gate::forUser($actor)->allows('viewAny', Meeting::class)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $actions) use ($actor): void {
            $actions->where(function (Builder $sign) use ($actor): void {
                $sign->where('status', MeetingStatus::Completed->value)
                    ->whereHas('attendees', fn (Builder $users) => $users->whereKey($actor->id))
                    ->whereDoesntHave('signatures', fn (Builder $signatures) => $signatures
                        ->where('user_id', $actor->id)->whereColumn('content_version', 'meetings.signature_content_version'));
            })->orWhere(function (Builder $view) use ($actor): void {
                $view->where('status', MeetingStatus::Scheduled->value)->whereNull('started_at')
                    ->where('created_by', '!=', $actor->id)->where(fn (Builder $secretary) => $secretary->whereNull('secretary_user_id')->orWhere('secretary_user_id', '!=', $actor->id))
                    ->where(function (Builder $members) use ($actor): void {
                        $members->where('chairman_user_id', $actor->id)
                            ->orWhereHas('attendees', fn (Builder $users) => $users->whereKey($actor->id));
                    })->whereNotExists(fn ($ack) => $ack->selectRaw('1')->from('meeting_acknowledgements')
                    ->whereColumn('meeting_id', 'meetings.id')->where('user_id', $actor->id));
            });
        });
    }

    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        return $this->visibleQuery($actor)
            ->select('meetings.*')->selectRaw('meetings.id IN ('.$this->actionRequiredQuery($actor)->select('meetings.id')->toSql().') AS requires_action', $this->actionRequiredQuery($actor)->select('meetings.id')->getBindings())
            ->with(['chairman', 'secretary', 'creator'])
            ->withCount(['attendees', 'agendaItems', 'resolutions'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['from_date'] ?? null, fn ($query, string $date) => $query->whereDate('meeting_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, string $date) => $query->whereDate('meeting_date', '<=', $date))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
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
            $this->participants->validate($data, $actor);
            $meeting = Meeting::query()->create([
                ...$this->meetingAttributes($data),
                'status' => MeetingStatus::Draft,
                'created_by' => $actor->id,
                'chairman_user_id' => $actor->id,
            ]);

            $this->syncAttendees($meeting, $data, true);
            $this->syncAgendaItems($meeting, $data);
            foreach ($data['initial_resolutions'] ?? [] as $index => $resolution) {
                $this->createResolution($meeting, [...$resolution, 'resolution_type' => MeetingResolutionType::Task->value, 'sort_order' => $index], $actor);
            }

            if ((bool) ($data['submit'] ?? false)) {
                $this->markSubmitted($meeting);
            }

            app(MeetingExecutionService::class)->refreshVersion($meeting);

            return $this->loadMeeting($meeting);
        });
    }

    public function update(Meeting $meeting, array $data): Meeting
    {
        return DB::transaction(function () use ($meeting, $data): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->ensureEditable($meeting);
            $this->participants->validate($data, User::query()->findOrFail($meeting->created_by), $meeting);
            $meeting->fill($this->meetingAttributes($data));
            $this->ensureEditable($meeting);
            $meeting->save();
            $this->syncAttendees($meeting, $data);
            $this->syncAgendaItems($meeting, $data);

            if ($meeting->status === MeetingStatus::Scheduled) {
                $this->validateSubmission($meeting);
            } elseif ((bool) ($data['submit'] ?? false)) {
                $this->markSubmitted($meeting);
            }

            app(MeetingExecutionService::class)->refreshVersion($meeting);

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

            app(MeetingExecutionService::class)->refreshVersion($meeting);

            return $this->loadMeeting($meeting);
        });
    }

    public function complete(Meeting $meeting, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('complete', $meeting);
            if ($meeting->status !== MeetingStatus::Scheduled) {
                throw new HttpException(409, 'فقط جلسه زمان‌بندی‌شده قابل اتمام است.');
            }
            if (! $meeting->resolutions()->exists()) {
                throw ValidationException::withMessages([
                    'resolutions' => 'برای اتمام جلسه حداقل یک مصوبه لازم است.',
                ]);
            }

            $execution = app(MeetingExecutionService::class);
            $execution->ensureCompleteForms($meeting);
            $execution->refreshVersion($meeting);
            $meeting->update(['status' => MeetingStatus::Completed, 'completed_at' => now()]);
            $execution->materialize($meeting);

            app(MeetingExecutionService::class)->refreshVersion($meeting);

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
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            $this->ensureResolutionEditable($meeting);
            if ($meeting->status === MeetingStatus::Draft && ! empty($data['task_id'])) {
                throw new HttpException(409, 'اتصال تسک پس از ارسال جلسه مجاز است.');
            }
            $this->ensureAgendaBelongsToMeeting($meeting, $data['agenda_item_id'] ?? null);
            if (! empty($data['task_id'])) {
                throw new HttpException(409, 'اتصال تسک موجود غیرفعال است.');
            }
            unset($data['task_id']);
            $this->ensureResolutionTypeMatchesTask(
                $data['resolution_type'] ?? MeetingResolutionType::Task->value,
                $data['task_id'] ?? null,
            );

            $resolution = $meeting->resolutions()->create([
                ...Arr::only($data, ['agenda_item_id', 'task_id', 'title', 'description', 'resolution_type', 'sort_order']),
                'created_by' => $actor->id,
            ]);

            app(MeetingExecutionService::class)->refreshVersion($meeting);

            return $this->loadResolution($resolution);
        });
    }

    public function updateResolution(Meeting $meeting, MeetingResolution $resolution, array $data, User $actor): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $resolution, $data, $actor): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            $resolution = MeetingResolution::query()->where('meeting_id', $meeting->id)->lockForUpdate()->findOrFail($resolution->id);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureResolutionEditable($meeting);
            if ($meeting->status === MeetingStatus::Draft && ! empty($data['task_id'])) {
                throw new HttpException(409, 'اتصال تسک پس از ارسال جلسه مجاز است.');
            }
            if (array_key_exists('agenda_item_id', $data)) {
                $this->ensureAgendaBelongsToMeeting($meeting, $data['agenda_item_id']);
            }
            if (! empty($data['task_id'])) {
                throw new HttpException(409, 'اتصال تسک موجود غیرفعال است.');
            }
            unset($data['task_id']);
            $this->ensureResolutionTypeMatchesTask(
                $data['resolution_type'] ?? $resolution->resolution_type->value,
                array_key_exists('task_id', $data) ? $data['task_id'] : $resolution->task_id,
            );
            if (array_key_exists('resolution_type', $data)
                && $resolution->resolution_type->value !== $data['resolution_type']
                && ($resolution->task_id !== null || $resolution->report()->exists())) {
                throw new HttpException(409, 'نوع مصوبه‌ای که به تسک یا گزارش متصل است قابل تغییر نیست.');
            }
            if ($resolution->execution_plan && isset($data['resolution_type']) && $data['resolution_type'] !== $resolution->resolution_type->value) {
                throw new HttpException(409, 'نوع مصوبه دارای فرم اجرایی قابل تغییر نیست.');
            }
            $resolution->update(Arr::only($data, ['agenda_item_id', 'title', 'description', 'resolution_type', 'sort_order']));
            app(MeetingExecutionService::class)->refreshVersion($meeting);

            return $this->loadResolution($resolution);
        });
    }

    public function deleteResolution(Meeting $meeting, MeetingResolution $resolution, User $actor): void
    {
        DB::transaction(function () use ($meeting, $resolution, $actor): void {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
            $this->ensureResolutionEditable($meeting);
            $resolution->delete();
            app(MeetingExecutionService::class)->refreshVersion($meeting);
        });
    }

    public function createTask(Meeting $meeting, MeetingResolution $resolution, array $data, User $actor): MeetingResolution
    {
        return app(MeetingExecutionService::class)->save($meeting, $resolution, $data, $actor, 'task');
    }

    public function linkTask(Meeting $meeting, MeetingResolution $resolution, Task $task, User $actor): MeetingResolution
    {
        Gate::forUser($actor)->authorize('manageResolutions', $meeting);
        $this->ensureResolutionBelongsToMeeting($meeting, $resolution);
        throw new HttpException(409, 'اتصال تسک موجود به مصوبه غیرفعال شده است؛ فرم اجرایی مصوبه را ثبت کنید.');
    }

    public function ensureResolutionBelongsToMeeting(Meeting $meeting, MeetingResolution $resolution): void
    {
        if ($resolution->meeting_id !== $meeting->id) {
            abort(404);
        }
    }

    public function reorderResolutions(Meeting $meeting, array $ids, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $ids, $actor): Meeting {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            $this->ensureResolutionEditable($meeting);
            $actual = $meeting->resolutions()->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($actual) !== count($ids) || array_diff($actual, $ids) || array_diff($ids, $actual)) {
                throw ValidationException::withMessages(['resolution_ids' => 'فهرست مرتب‌شده باید دقیقاً شامل همهٔ مصوبات همین جلسه باشد.']);
            }
            $cases = [];
            $bindings = [];
            foreach ($ids as $index => $id) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $id, $index);
            }
            $bindings[] = $meeting->id;
            $table = DB::connection()->getQueryGrammar()->wrapTable('meeting_resolutions');
            DB::update('UPDATE '.$table.' SET sort_order = CASE id '.implode(' ', $cases).' END WHERE meeting_id = ?', $bindings);
            app(MeetingExecutionService::class)->refreshVersion($meeting);

            return $this->loadMeeting($meeting);
        });
    }

    private function markSubmitted(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Draft) {
            throw new HttpException(409, 'فقط پیش‌نویس جلسه قابل ارسال است.');
        }
        $this->validateSubmission($meeting);
        $meeting->update(['status' => MeetingStatus::Scheduled, 'submitted_at' => now()]);
    }

    private function ensureResolutionTypeMatchesTask(string $type, ?int $taskId): void
    {
        if ($type === MeetingResolutionType::Report->value && $taskId !== null) {
            throw ValidationException::withMessages([
                'task_id' => 'مصوبه با نوع گزارش نمی‌تواند به تسک متصل باشد.',
            ]);
        }
    }

    private function validateSubmission(Meeting $meeting): void
    {
        $messages = [];
        foreach ([
            'short_description' => 'توضیح مختصر', 'duration_minutes' => 'مدت جلسه',
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
        if ($meeting->resolutions()->whereNotNull('agenda_item_id')->whereNotIn('agenda_item_id', $ids)->exists()) {
            throw ValidationException::withMessages(['agenda_items' => 'دستور جلسه مرتبط با مصوبه قابل حذف نیست.']);
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
        if (! $meeting->isEditableBeforeStart()) {
            throw new HttpException(409, 'ویرایش جلسه فقط پیش از شروع و در وضعیت پیش‌نویس یا زمان‌بندی‌شده مجاز است.');
        }
    }

    private function ensureResolutionEditable(Meeting $meeting): void
    {
        if (! in_array($meeting->status, [MeetingStatus::Draft, MeetingStatus::Scheduled], true)) {
            throw new HttpException(409, 'مدیریت مصوبات فقط پیش از اتمام جلسه مجاز است.');
        }
    }

    private function ensureScheduled(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Scheduled) {
            throw new HttpException(409, 'مدیریت مصوبات فقط برای جلسه زمان‌بندی‌شده مجاز است.');
        }
    }

    /** @param array<int, int|string> $assigneeIds */
    private function ensureTaskAssigneesAttendMeeting(Meeting $meeting, array $assigneeIds): void
    {
        $memberIds = $meeting->attendees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $invalidIds = array_diff(array_map('intval', $assigneeIds), $memberIds);
        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'assignee_ids' => 'همه مسئولان تسک مصوبه باید از اعضای همان جلسه باشند.',
            ]);
        }
    }

    private function meetingAttributes(array $data): array
    {
        return Arr::only($data, ['title', 'short_description', 'location', 'meeting_date', 'start_time', 'duration_minutes', 'chairman_user_id', 'secretary_user_id']);
    }

    private function hasAdministrativeAccess(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasAccessRole(AccessRoles::ADMIN);
    }

    private function loadMeeting(Meeting $meeting): Meeting
    {
        return $meeting->fresh([
            'chairman', 'secretary', 'creator', 'attendees', 'agendaItems',
            'signatures' => fn ($query) => $query->select(['id', 'meeting_id', 'user_id', 'content_version', 'signed_at']),
            'resolutions.agendaItem', 'resolutions.creator', 'resolutions.task.assignees', 'resolutions.task.tags',
            'resolutions.report' => fn ($query) => $query->withExists('tasks'),
            'resolutions.report.recipient', 'resolutions.report.ccUsers', 'resolutions.report.tags', 'resolutions.report.creator',
        ]);
    }

    private function loadResolution(MeetingResolution $resolution): MeetingResolution
    {
        return $resolution->fresh(['agendaItem', 'creator', 'task.requester', 'task.creator', 'task.assignees', 'task.followers', 'task.supervisors', 'task.tags', 'task.financialProvider', 'task.equipmentProvider', 'task.meetingResolution.meeting', 'report' => fn ($query) => $query->withExists('tasks'), 'report.recipient', 'report.ccUsers', 'report.tags', 'report.creator']);
    }
}
