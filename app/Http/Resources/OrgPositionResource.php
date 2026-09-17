<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrgPositionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $children = $this->relationLoaded('children') ? $this->children : collect();
        $user = $this->relationLoaded('users') ? $this->users->first() : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'parent_id' => $this->parent_id,
            'user_id' => $user?->id,
            'sort_order' => $this->sort_order,
            'request_up_levels' => $this->request_up_levels,
            'assignment_down_levels' => $this->assignment_down_levels,
            'user' => $user ? new UserSummaryResource($user) : null,
            'children' => self::collection($children),
            'children_count' => $children->count(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
