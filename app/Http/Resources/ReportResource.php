<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'status' => $this->status->value,
            'sent_at' => $this->sent_at?->toISOString(),
            'viewed_at' => $this->viewed_at?->toISOString(),
            'attachments' => MediaFileResource::collection($this->whenLoaded('attachments')),
            'meeting_resolution_id' => $this->meeting_resolution_id,
            'meeting' => $this->whenLoaded('resolution', fn () => $this->resolution === null ? null : [
                'id' => $this->resolution->meeting->id,
                'title' => $this->resolution->meeting->title,
                'meeting_date' => $this->resolution->meeting->meeting_date?->format('Y-m-d'),
            ]),
            'resolution' => $this->whenLoaded('resolution', fn () => $this->resolution === null ? null : [
                'id' => $this->resolution->id,
                'title' => $this->resolution->title,
                'agenda_item_id' => $this->resolution->agenda_item_id,
                'resolution_type' => $this->resolution->resolution_type->value,
            ]),
            'title' => $this->title,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'recipient' => new UserSummaryResource($this->whenLoaded('recipient')),
            'cc_users' => UserSummaryResource::collection($this->whenLoaded('ccUsers')),
            'tags' => TaskTagResource::collection($this->whenLoaded('tags')),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
