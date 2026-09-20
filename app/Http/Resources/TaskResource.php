<?php

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

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
            'requester' => new UserSummaryResource($this->requester),
            'creator' => new UserSummaryResource($this->creator),
            'assignees' => UserSummaryResource::collection($this->assignees),
            'followers' => UserSummaryResource::collection($this->followers),
            'supervisors' => UserSummaryResource::collection($this->supervisors),
            'tags' => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'title' => $tag->title,
            ])->values(),
            'attachments' => MediaFileResource::collection($this->attachments),
            'planning_items' => $this->planningItems->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'weight' => $item->weight,
                'progress_percentage' => $item->progress_percentage,
                'sort_order' => $item->sort_order,
            ])->values(),
            'financial_resources' => $this->financial_resources,
            'financial_estimated_cost' => $this->financial_estimated_cost,
            'financial_provider' => $this->financialProvider
                ? new UserSummaryResource($this->financialProvider)
                : null,
            'equipment_resources' => $this->equipment_resources,
            'equipment_estimated_cost' => $this->equipment_estimated_cost,
            'equipment_provider' => $this->equipmentProvider
                ? new UserSummaryResource($this->equipmentProvider)
                : null,
            'rejection_reason' => $this->rejection_reason,
            'meeting_source' => $this->meetingResolution ? [
                'meeting_id' => $this->meetingResolution->meeting_id,
                'meeting_title' => $this->meetingResolution->meeting?->title,
                'resolution_id' => $this->meetingResolution->id,
                'resolution_title' => $this->meetingResolution->title,
            ] : null,
            'workflow_history' => $this->whenLoaded(
                'workflowHistory',
                fn () => TaskWorkflowHistoryResource::collection($this->workflowHistory),
            ),
            'allowed_actions' => $this->allowedActions($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function allowedActions(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        $pendingRequest = $this->status === TaskStatus::PendingApproval
            && $this->submission_type === TaskSubmissionType::Request;

        return [
            'view' => $gate->allows('view', $this->resource),
            'edit' => $gate->allows('update', $this->resource),
            'delete' => $gate->allows('delete', $this->resource),
            'submit' => $gate->allows('submit', $this->resource),
            'approve' => $pendingRequest && $gate->allows('approve', $this->resource),
            'reject' => $pendingRequest && $gate->allows('reject', $this->resource),
            'request_revision' => $pendingRequest && $gate->allows('requestRevision', $this->resource),
            'change_status' => in_array($this->status, [TaskStatus::InProgress, TaskStatus::NotCompleted], true)
                && $gate->allows('changeStatus', $this->resource),
            'update_progress' => $this->status === TaskStatus::InProgress
                && $gate->allows('updateProgress', $this->resource),
            'comment' => $gate->allows('comment', $this->resource),
        ];
    }
}
