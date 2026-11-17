<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use App\Http\Requests\Concerns\ValidatesListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    use ValidatesListFilters;

    public function rules(): array
    {
        return [
            ...$this->textFilterRules(['search', 'title', 'description', 'assignee_search', 'financial_search', 'equipment_search', 'tag']),
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'submission_type' => ['nullable', Rule::enum(TaskSubmissionType::class)],
            'scope' => ['nullable', Rule::in(['created_by_me', 'assigned_to_me', 'involved', 'action_required', 'all'])],
            ...array_fill_keys(['user_id', 'assignee_id', 'follower_id', 'supervisor_id', 'financial_provider_user_id', 'equipment_provider_user_id'], ['nullable', 'integer', 'min:1', 'exists:users,id']),
            ...$this->dateRangeRules('due_from', 'due_to'),
            ...$this->dateRangeRules('created_from', 'created_to'),
            ...$this->numberRangeRules('duration_minutes_min', 'duration_minutes_max'),
            ...$this->numberRangeRules('financial_estimated_cost_min', 'financial_estimated_cost_max', false),
            ...$this->numberRangeRules('equipment_estimated_cost_min', 'equipment_estimated_cost_max', false),
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'longest_duration', 'shortest_duration', 'highest_progress', 'lowest_progress'])],
            ...$this->paginationRules(),
        ];
    }
}
