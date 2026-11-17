<?php

namespace App\Services;

use App\Enums\TaskParticipantRole;
use App\Models\OrgPosition;
use App\Models\User;
use App\Support\ListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListFilterOptionsService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReportService $reports,
        private readonly UserService $users,
    ) {}

    public function taskUsers(array $filters, User $actor): LengthAwarePaginator
    {
        $records = $this->tasks->listingQuery($filters, $actor);
        $people = User::query();
        $field = $filters['field'] ?? 'assignee_id';
        $role = match ($field) {
            'assignee_id' => TaskParticipantRole::Assignee,
            'follower_id' => TaskParticipantRole::Follower,
            'supervisor_id' => TaskParticipantRole::Supervisor,
            default => null,
        };
        $participants = fn () => DB::table('task_participants')->select('user_id')
            ->whereIn('task_id', (clone $records)->select('tasks.id'));
        if ($role !== null) {
            $people->whereIn('users.id', $participants()->where('role', $role->value));
        } elseif ($field === 'user_id') {
            $people->where(function (Builder $related) use ($records, $participants): void {
                foreach (['created_by', 'requester_id', 'financial_provider_user_id', 'equipment_provider_user_id'] as $column) {
                    $related->orWhereIn('users.id', (clone $records)->select('tasks.'.$column));
                }
                $related->orWhereIn('users.id', $participants());
            });
        } else {
            // field has already been checked against a fixed FormRequest whitelist.
            $people->whereIn('users.id', (clone $records)->select('tasks.'.$field));
        }

        return $this->paginatePeople($people, $filters);
    }

    public function reportUsers(array $filters, User $actor): LengthAwarePaginator
    {
        $column = ($filters['field'] ?? 'recipient_user_id') === 'creator_user_id' ? 'created_by' : 'recipient_user_id';
        $people = User::query()->whereIn('users.id', $this->reports->visibleQuery($actor)->select('reports.'.$column));

        return $this->paginatePeople($people, $filters);
    }

    public function userPositions(array $filters, User $actor): LengthAwarePaginator
    {
        $positions = OrgPosition::query()->whereHas('users', fn (Builder $users) => $users
            ->whereIn('users.id', $this->users->visibleQuery($actor)->select('users.id')));
        if (ListFilters::has($filters, 'search')) {
            ListFilters::contains($positions, 'org_positions.title', $filters['search']);
        }

        return $positions->select(['org_positions.id', 'org_positions.title'])->orderBy('org_positions.id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    private function paginatePeople(Builder $people, array $filters): LengthAwarePaginator
    {
        if (ListFilters::has($filters, 'search')) {
            ListFilters::fullName($people, $filters['search']);
        }

        return $people->select(['users.id', 'users.first_name', 'users.last_name', 'users.is_active'])->orderBy('users.id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
