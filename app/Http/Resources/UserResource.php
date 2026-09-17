<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'org_code' => $this->org_code,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'birth_date' => $this->birth_date?->toDateString(),
            'internal_phone' => $this->internal_phone,
            'avatar_file_id' => $this->avatar_file_id,
            'avatar' => $this->relationLoaded('avatar') && $this->avatar
                ? new MediaFileResource($this->avatar)
                : null,
            'signature_file_id' => $this->signature_file_id,
            'title' => $this->title,
            'is_active' => $this->is_active,
            'access_roles' => $this->whenLoaded('accessRoles', fn () => $this->accessRoles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values()),
            'permissions' => $this->whenLoaded('accessRoles', fn () => $this->permissionNames()),
            'org_positions' => $this->whenLoaded('orgPositions', fn () => $this->orgPositions->map(fn ($position) => [
                'id' => $position->id,
                'title' => $position->title,
                'parent_id' => $position->parent_id,
            ])->values()),
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'tasks_count' => (int) ($this->tasks_count ?? 0),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'special_dates' => $this->whenLoaded('specialDates', fn () => $this->specialDates->map(fn ($date) => [
                'id' => $date->id,
                'title' => $date->title,
                'date' => $date->date?->toDateString(),
                'description' => $date->description,
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
