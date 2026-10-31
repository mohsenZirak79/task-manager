<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\OrgPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class MeetingParticipantService
{
    public function descendants(User $creator): Builder
    {
        $parents = OrgPosition::query()->pluck('parent_id', 'id')->all();
        $roots = $creator->orgPositions()->pluck('org_positions.id')->map(fn ($id) => (int) $id)->all();
        $ids = [];
        foreach ($parents as $id => $parent) {
            $seen = [(int) $id];
            while ($parent !== null && ! in_array((int) $parent, $seen, true)) {
                if (in_array((int) $parent, $roots, true)) {
                    $ids[] = (int) $id;
                    break;
                }
                $seen[] = (int) $parent;
                $parent = $parents[$parent] ?? null;
            }
        }

        return User::query()->where('is_active', true)->whereKeyNot($creator->id)
            ->whereHas('orgPositions', fn (Builder $query) => $query->whereIn('org_positions.id', $ids));
    }

    public function validate(array $data, User $creator, ?Meeting $meeting = null): void
    {
        $chairman = $meeting?->chairman_user_id ?? $creator->id;
        if (array_key_exists('chairman_user_id', $data) && (int) $data['chairman_user_id'] !== $chairman) {
            throw ValidationException::withMessages(['chairman_user_id' => 'رئیس جلسه همان ایجادکننده است و قابل تغییر نیست.']);
        }
        $ids = array_values(array_unique(array_map('intval', [
            ...($data['attendee_user_ids'] ?? []),
            ...(isset($data['secretary_user_id']) ? [$data['secretary_user_id']] : []),
        ])));
        if ($ids === []) {
            return;
        }
        $allowed = $this->descendants($creator)->whereKey($ids)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $errors = [];
        if (isset($data['secretary_user_id']) && ! in_array((int) $data['secretary_user_id'], [$creator->id, ...$allowed], true)) {
            $errors['secretary_user_id'] = 'دبیر باید ایجادکننده جلسه یا یکی از زیرمجموعه‌های سازمانی او باشد.';
        }
        if (array_diff(array_map('intval', $data['attendee_user_ids'] ?? []), $allowed)) {
            $errors['attendee_user_ids'] = 'حاضرین انتخابی باید از زیرمجموعه‌های سازمانی ایجادکننده جلسه باشند.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
