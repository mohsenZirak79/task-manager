<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResolutionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agenda_item_id' => $this->agenda_item_id,
            'title' => $this->title,
            'description' => $this->description,
            'resolution_type' => $this->resolution_type->value,
            'sort_order' => $this->sort_order,
            'execution_plan' => $this->execution_plan,
            'materialized_at' => $this->materialized_at?->toISOString(),
            'execution_status' => ($this->materialized_at || $this->task_id || $this->report) ? 'created' : ($this->execution_plan ? 'registered' : 'unregistered'),
            'task' => $this->task ? new MeetingTaskSummaryResource($this->task) : null,
            'report' => $this->report ? new ReportResource($this->report) : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
