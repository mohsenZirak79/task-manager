<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingTaskSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'progress_percentage' => $this->progress_percentage,
            'due_date' => $this->due_date?->toDateString(),
            'duration_minutes' => $this->duration_minutes,
            'assignees' => UserSummaryResource::collection($this->whenLoaded('assignees')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'title' => $tag->title,
            ])->values()),
        ];
    }
}
