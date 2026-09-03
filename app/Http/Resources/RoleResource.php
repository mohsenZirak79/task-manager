<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $children = $this->relationLoaded('children') ? $this->children : collect();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'parent_id' => $this->parent_id,
            'user_id' => $this->user_id,
            'sort_order' => $this->sort_order,
            'visible_tabs' => $this->visible_tabs ?? [],
            'request_up_levels' => $this->request_up_levels,
            'assignment_down_levels' => $this->assignment_down_levels,
            'user' => $this->relationLoaded('user') && $this->user
                ? new RoleUserResource($this->user)
                : null,
            'children' => self::collection($children),
            'children_count' => $children->count(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
