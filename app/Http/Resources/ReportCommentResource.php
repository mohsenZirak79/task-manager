<?php

namespace App\Http\Resources;

use App\Enums\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ReportCommentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $request->user()?->loadMissing('accessRoles.permissions');
        $gate = Gate::forUser($request->user());
        $report = $this->report;
        $active = $report->status !== ReportStatus::Inactive;
        $live = ! $this->trashed();
        $manage = $live && $active && $gate->allows('manageComment', [$report, $this->resource]);

        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'parent_id' => $this->parent_id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'body' => $this->trashed() ? null : $this->body,
            'mentions' => $this->trashed() ? [] : UserSummaryResource::collection($this->whenLoaded('mentions')),
            'is_deleted' => $this->trashed(),
            'reactions' => [
                'like' => (int) $this->like_count,
                'dislike' => (int) $this->dislike_count,
                'my_reaction' => $this->reactions->first()?->reaction,
            ],
            'allowed_actions' => [
                'edit' => $manage,
                'delete' => $manage,
                'reply' => $live && $active && $gate->allows('replyComment', [$report, $this->resource]),
                'react' => $live && $active && $gate->allows('reactComment', [$report, $this->resource]),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
