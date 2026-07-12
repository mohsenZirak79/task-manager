<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserRelationExceptionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'from_user_id' => $this->from_user_id, 'to_user_id' => $this->to_user_id, 'permission_type' => $this->permission_type, 'description' => $this->description, 'from_user' => $this->whenLoaded('fromUser', fn () => new UserResource($this->fromUser)), 'to_user' => $this->whenLoaded('toUser', fn () => new UserResource($this->toUser)), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
