<?php

namespace App\Services;

use App\Models\Report;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommentMentionService
{
    public function eligibleUsers(Task|Report $subject, ?string $search = null): Collection
    {
        if ($subject instanceof Task) {
            $subject->loadMissing('participantRecords');
            $ids = $subject->participantRecords->pluck('user_id')->merge([
                $subject->created_by, $subject->requester_id,
                $subject->financial_provider_user_id, $subject->equipment_provider_user_id,
            ])->filter()->unique()->all();
        } else {
            $subject->loadMissing(['ccUsers', 'resolution.meeting.attendees']);
            $meeting = $subject->resolution?->meeting;
            $ids = $subject->ccUsers->pluck('id')->merge([
                $subject->created_by, $subject->recipient_user_id,
                $meeting?->created_by, $meeting?->chairman_user_id, $meeting?->secretary_user_id,
            ])->merge($meeting?->attendees->pluck('id') ?? [])->filter()->unique()->all();
        }

        return User::query()->where('is_active', true)
            ->where(function ($query) use ($ids, $subject): void {
                $query->whereKey($ids)->orWhereHas('accessRoles', function ($query) use ($subject): void {
                    $query->whereIn('slug', $subject instanceof Task ? ['super_admin'] : ['admin', 'super_admin']);
                    if ($subject instanceof Task) {
                        $query->orWhereHas('permissions', fn ($query) => $query->where('name', 'tasks:view_all'));
                    }
                });
            })
            ->when(filled($search), fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('org_code', 'like', "%{$search}%");
            }))
            ->with('accessRoles.permissions')->orderBy('first_name')->orderBy('id')->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $subject))->values();
    }

    public function sync(TaskComment $comment, Task|Report $subject, array $data): void
    {
        // Omission on PATCH preserves mentions; [] explicitly removes all of them.
        if (! array_key_exists('mention_user_ids', $data)) {
            return;
        }
        $ids = array_map('intval', $data['mention_user_ids']);
        $eligible = $this->eligibleUsers($subject)->modelKeys();
        if (array_diff($ids, $eligible) !== []) {
            throw ValidationException::withMessages([
                'mention_user_ids' => 'فقط کاربران فعال دارای دسترسی مشاهده این رکورد قابل منشن هستند.',
            ]);
        }
        $comment->mentions()->sync($ids);
    }
}
