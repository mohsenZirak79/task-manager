<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class TaskCommentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $deleted = $this->trashed();
        $gate = Gate::forUser($request->user());

        return [
            'id' => $this->id,
            'body' => $deleted ? null : $this->body,
            'is_deleted' => $deleted,
            'user' => [
                'id' => $this->user->id,
                'full_name' => trim($this->user->first_name.' '.$this->user->last_name),
                'avatar_file_id' => $this->user->avatar_file_id,
            ],
            'parent_id' => $this->parent_id,
            'replies_count' => (int) $this->replies_count,
            'reactions' => [
                'like' => (int) $this->like_count,
                'dislike' => (int) $this->dislike_count,
                'my_reaction' => $this->reactions->first()?->reaction,
            ],
            'allowed_actions' => [
                'edit' => ! $deleted && $gate->allows('update', $this->resource),
                'delete' => ! $deleted && $gate->allows('delete', $this->resource),
                'reply' => ! $deleted && $gate->allows('reply', $this->resource),
                'react' => ! $deleted && $gate->allows('react', $this->resource),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
