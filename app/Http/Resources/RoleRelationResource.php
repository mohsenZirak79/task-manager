<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleRelationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'parent_role_id' => $this->parent_role_id, 'child_role_id' => $this->child_role_id, 'relation_type' => $this->relation_type, 'parent_role' => $this->whenLoaded('parentRole', fn () => new RoleResource($this->parentRole)), 'child_role' => $this->whenLoaded('childRole', fn () => new RoleResource($this->childRole)), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
