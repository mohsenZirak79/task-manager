<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingSummaryResource extends JsonResource
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
            'chairman' => $this->chairman ? new UserSummaryResource($this->chairman) : null,
            'secretary' => $this->secretary ? new UserSummaryResource($this->secretary) : null,
            'creator' => $this->creator ? new UserSummaryResource($this->creator) : null,
            'attendees_count' => $this->attendees_count ?? null,
            'agenda_items_count' => $this->agenda_items_count ?? null,
            'resolutions_count' => $this->resolutions_count ?? null,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
