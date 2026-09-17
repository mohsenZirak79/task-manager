<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'request_description' => $this->request_description,
            'duration_minutes' => $this->duration_minutes,
            'due_date' => $this->due_date?->toDateString(),
            'progress_percentage' => $this->progress_percentage,
            'status' => $this->status->value,
            'submission_type' => $this->submission_type?->value,
            'requester' => new RoleUserResource($this->requester),
            'creator' => new RoleUserResource($this->creator),
            'assignees' => RoleUserResource::collection($this->assignees),
            'followers' => RoleUserResource::collection($this->followers),
            'supervisors' => RoleUserResource::collection($this->supervisors),
            'financial_resources' => $this->financial_resources,
            'financial_estimated_cost' => $this->financial_estimated_cost,
            'financial_provider' => $this->financialProvider
                ? new RoleUserResource($this->financialProvider)
                : null,
            'equipment_resources' => $this->equipment_resources,
            'equipment_estimated_cost' => $this->equipment_estimated_cost,
            'equipment_provider' => $this->equipmentProvider
                ? new RoleUserResource($this->equipmentProvider)
                : null,
            'rejection_reason' => $this->rejection_reason,
            'workflow_history' => $this->whenLoaded(
                'workflowHistory',
                fn () => TaskWorkflowHistoryResource::collection($this->workflowHistory),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
