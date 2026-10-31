<?php

namespace App\Http\Resources;

use App\Enums\MeetingStatus;
use App\Models\MediaFile;
use App\Services\MeetingSignatureContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MeetingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $request->user()->loadMissing('accessRoles.permissions');
        $content = app(MeetingSignatureContent::class);
        $version = $content->version($content->snapshot($this->resource));
        $this->resource->loadMissing(['signatures' => fn ($query) => $query->select(['id', 'meeting_id', 'user_id', 'content_version', 'signed_at'])]);
        $byUser = $this->signatures->groupBy('user_id');
        $maySign = Gate::forUser($request->user())->allows('sign', $this->resource);
        $actorSigned = $byUser->get($request->user()->id, collect())->contains('content_version', $version);
        $views = DB::table('meeting_acknowledgements')->where('meeting_id', $this->id)
            ->pluck('viewed_at', 'user_id')->map(fn ($date) => $date === null ? null : Carbon::parse($date, config('app.timezone'))->toISOString());
        $formsReady = $this->resolutions->every(fn ($row) => $row->execution_plan || $row->task_id || $row->report);
        $signaturesCount = $this->attendees->filter(fn ($user) => $byUser->get($user->id, collect())->contains('content_version', $version))->count();
        $membersCount = DB::table('meeting_attendees')->where('meeting_id', $this->id)->count();
        $allSigned = $membersCount > 0 && $membersCount === $signaturesCount && $this->attendees->every(fn ($user) => $user->is_active);
        $requiresAction = ($maySign && ! $actorSigned)
            || ($this->status === MeetingStatus::Scheduled && ! $views->has($request->user()->id) && Gate::forUser($request->user())->allows('acknowledge', $this->resource));

        $fileIds = $this->resolutions->flatMap(fn ($row) => $row->execution_plan['payload']['attachment_file_ids'] ?? [])->unique()->values();

        return [
            'execution_files' => MediaFileResource::collection($fileIds->isEmpty() ? collect() : MediaFile::query()->whereKey($fileIds)->get()),
            'signature_content_version' => $version,
            'requires_action' => $requiresAction,
            'execution_readiness' => ['forms_complete' => $formsReady, 'members_count' => $membersCount, 'signed_count' => $signaturesCount, 'all_signed' => $allSigned, 'ready' => $this->status === MeetingStatus::Completed && $allSigned],
            'id' => $this->id,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'location' => $this->location,
            'meeting_date' => $this->meeting_date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'viewed_at' => $views->get($request->user()->id),
            'allowed_actions' => [
                'sign' => $maySign && ! $actorSigned,
                'manage_resolutions' => in_array($this->status, [MeetingStatus::Draft, MeetingStatus::Scheduled], true)
                    && Gate::forUser($request->user())->allows('manageResolutions', $this->resource),
                'complete' => $this->status === MeetingStatus::Scheduled
                    && $this->resolutions->isNotEmpty()
                    && $formsReady
                    && Gate::forUser($request->user())->allows('complete', $this->resource),
                'edit' => Gate::forUser($request->user())->allows('update', $this->resource),
                'start' => Gate::forUser($request->user())->allows('start', $this->resource),
                'acknowledge' => Gate::forUser($request->user())->allows('acknowledge', $this->resource),
            ],
            'chairman' => $this->chairman ? new UserSummaryResource($this->chairman) : null,
            'secretary' => $this->secretary ? new UserSummaryResource($this->secretary) : null,
            'creator' => $this->creator ? new UserSummaryResource($this->creator) : null,
            'attendees' => $this->attendees->map(function ($attendee) use ($byUser, $version, $maySign, $request, $views) {
                $signatures = $byUser->get($attendee->id, collect());
                $signature = $signatures->firstWhere('content_version', $version) ?? $signatures->first();

                return new MeetingAttendeeResource($attendee, $signature, $version, $maySign && $attendee->id === $request->user()->id, $views->get($attendee->id));
            }),
            'agenda_items' => MeetingAgendaItemResource::collection($this->whenLoaded('agendaItems')),
            'resolutions' => MeetingResolutionResource::collection($this->whenLoaded('resolutions')),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
