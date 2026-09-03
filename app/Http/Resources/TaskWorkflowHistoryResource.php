<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskWorkflowHistoryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'old_progress' => $this->old_progress,
            'new_progress' => $this->new_progress,
            'reason' => $this->reason,
            'actor' => $this->relationLoaded('actor') ? new RoleUserResource($this->actor) : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
