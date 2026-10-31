<?php

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\MediaFile;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\MeetingSignature;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MeetingExecutionService
{
    public function __construct(private readonly TaskService $tasks, private readonly ReportService $reports, private readonly MeetingSignatureContent $content) {}

    public function save(Meeting $meeting, MeetingResolution $resolution, array $payload, User $actor, string $kind): MeetingResolution
    {
        return DB::transaction(function () use ($meeting, $resolution, $payload, $actor, $kind): MeetingResolution {
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            $resolution = MeetingResolution::query()->where('meeting_id', $meeting->id)->lockForUpdate()->findOrFail($resolution->id);
            if (! in_array($meeting->status, [MeetingStatus::Draft, MeetingStatus::Scheduled], true)) {
                throw new ConflictHttpException('فرم اجرایی مصوبه باید پیش از اتمام جلسه ثبت شود.');
            }
            if ($resolution->resolution_type->value !== $kind || $resolution->task_id || $resolution->report()->exists()) {
                throw new ConflictHttpException('نوع مصوبه یا ارتباط ثبت‌شده با این عملیات سازگار نیست.');
            }
            Gate::forUser($actor)->authorize('create', $kind === 'task' ? Task::class : Report::class);
            $this->ensureMembers($meeting, $payload, $kind);
            $payload = $kind === 'task' ? [...$payload, 'submit' => true] : [...$payload, 'status' => 'sent'];
            if ($kind === 'report') {
                $payload['submission_key'] = 'resolution-'.$resolution->id.'-'.$this->content->version(['report_payload' => Arr::except($payload, ['submission_key'])]);
            }
            if ($kind === 'task') {
                $payload = $this->tasks->prepareCreation($payload, $actor);
            }
            $ids = $payload['attachment_file_ids'] ?? [];
            if (MediaFile::query()->whereKey($ids)->where('category', 'attachment')
                ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('uploaded_by', $actor->id))->count() !== count($ids)) {
                throw ValidationException::withMessages(['attachment_file_ids' => 'فایل‌های مصوبه باید پیوست معتبر و متعلق به ثبت‌کننده باشند.']);
            }
            $current = $resolution->execution_plan;
            if ($current && $current['actor_id'] === $actor->id && $current['payload'] == $payload) {
                return $resolution->load(['task', 'report']);
            }
            $resolution->update(['execution_plan' => ['kind' => $kind, 'payload' => $payload, 'actor_id' => $actor->id, 'registered_at' => now()->toISOString()]]);
            $this->refreshVersion($meeting);

            return $resolution->load(['task', 'report']);
        });
    }

    public function ensureMembers(Meeting $meeting, array $payload, string $kind): void
    {
        $members = DB::table('meeting_attendees')->where('meeting_id', $meeting->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $fields = $kind === 'task' ? ['assignee_ids', 'follower_ids', 'supervisor_ids'] : ['recipient_user_id', 'cc_user_ids'];
        $errors = [];
        foreach ($fields as $field) {
            $ids = (array) ($payload[$field] ?? []);
            if (array_diff(array_map('intval', $ids), $members)) {
                $errors[$field] = 'افراد انتخاب‌شده باید از اعضای همین جلسه باشند.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function ensureCompleteForms(Meeting $meeting): void
    {
        $missing = $meeting->resolutions()->whereNull('execution_plan')->whereNull('task_id')->whereDoesntHave('report')->exists();
        if ($missing) {
            throw ValidationException::withMessages(['resolutions' => 'پیش از اتمام جلسه، فرم تسک یا گزارش همهٔ مصوبات را ثبت کنید.']);
        }
    }

    public function refreshVersion(Meeting $meeting): string
    {
        $fresh = $meeting->fresh(['attendees', 'agendaItems', 'resolutions.report']);
        $version = $this->content->version($this->content->snapshot($fresh));
        DB::table('meetings')->where('id', $meeting->id)->update(['signature_content_version' => $version]);
        $meeting->signature_content_version = $version;

        return $version;
    }

    public function ready(Meeting $meeting): bool
    {
        if ($meeting->status !== MeetingStatus::Completed) {
            return false;
        }
        $ids = DB::table('meeting_attendees')->where('meeting_id', $meeting->id)->pluck('user_id');
        if ($ids->isEmpty() || User::query()->whereKey($ids)->where('is_active', true)->count() !== $ids->count()) {
            return false;
        }
        $version = $this->content->version($this->content->snapshot($meeting->fresh(['attendees', 'agendaItems', 'resolutions.report'])));

        return MeetingSignature::query()->where('meeting_id', $meeting->id)->where('content_version', $version)->whereIn('user_id', $ids)->count() === $ids->count();
    }

    public function assertReady(Meeting $meeting): void
    {
        if (! $this->ready($meeting)) {
            throw new ConflictHttpException('ایجاد نهایی فقط پس از اتمام جلسه و امضای نسخه نهایی توسط تمام اعضا مجاز است.');
        }
    }

    // Caller holds the same meeting lock as completion/signature mutations.
    public function materialize(Meeting $meeting): void
    {
        if (! $this->ready($meeting)) {
            return;
        }
        $plans = $meeting->resolutions()->with('report')->whereNotNull('execution_plan')->whereNull('materialized_at')->orderBy('id')->lockForUpdate()->get();
        $actors = User::query()->where('is_active', true)->with(['accessRoles.permissions', 'orgPositions'])->whereKey($plans->map(fn ($row) => $row->execution_plan['actor_id'])->unique())->get()->keyBy('id');
        foreach ($plans as $resolution) {
            $plan = $resolution->execution_plan;
            $actor = $actors->get($plan['actor_id']);
            if (! $actor) {
                throw ValidationException::withMessages(['resolutions' => 'ثبت‌کنندهٔ فرم مصوبه دیگر فعال نیست.']);
            }
            Gate::forUser($actor)->authorize('manageResolutions', $meeting);
            Gate::forUser($actor)->authorize('create', $plan['kind'] === 'task' ? Task::class : Report::class);
            $this->ensureMembers($meeting, $plan['payload'], $plan['kind']);
            if ($resolution->task_id || $resolution->report !== null) {
                throw new ConflictHttpException('این مصوبه قبلاً اجرا شده است.');
            }
            if ($plan['kind'] === 'task') {
                $task = $this->tasks->create([...$plan['payload'], 'submit' => true], $actor);
                $resolution->update(['task_id' => $task->id]);
            } else {
                $this->reports->create($meeting, $resolution, [...$plan['payload'], 'status' => 'sent'], $actor);
            }
            $resolution->update(['materialized_at' => now()]);
        }
    }
}
