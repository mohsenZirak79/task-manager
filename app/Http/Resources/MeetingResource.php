<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'location' => $this->location,
            'meeting_date' => $this->meeting_date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'viewed_at' => \Illuminate\Support\Facades\DB::table('meeting_acknowledgements')
                ->where('meeting_id', $this->id)->where('user_id', $request->user()->id)->value('viewed_at'),
            'allowed_actions' => [
                'edit' => \Illuminate\Support\Facades\Gate::forUser($request->user())->allows('update', $this->resource),
                'start' => \Illuminate\Support\Facades\Gate::forUser($request->user())->allows('start', $this->resource),
                'acknowledge' => \Illuminate\Support\Facades\Gate::forUser($request->user())->allows('acknowledge', $this->resource),
            ],
            'chairman' => $this->chairman ? new UserSummaryResource($this->chairman) : null,
            'secretary' => $this->secretary ? new UserSummaryResource($this->secretary) : null,
            'creator' => $this->creator ? new UserSummaryResource($this->creator) : null,
            'attendees' => UserSummaryResource::collection($this->whenLoaded('attendees')),
            'agenda_items' => MeetingAgendaItemResource::collection($this->whenLoaded('agendaItems')),
            'resolutions' => MeetingResolutionResource::collection($this->whenLoaded('resolutions')),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
