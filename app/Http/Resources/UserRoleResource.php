<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserRoleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'user_id' => $this->user_id, 'role_id' => $this->role_id, 'role' => $this->whenLoaded('role', fn () => new RoleResource($this->role)), 'started_at' => $this->started_at?->toISOString(), 'ended_at' => $this->ended_at?->toISOString(), 'is_active' => $this->is_active, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
