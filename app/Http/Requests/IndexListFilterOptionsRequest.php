<?php

namespace App\Http\Requests;

use App\Enums\TaskSubmissionType;
use App\Http\Requests\Concerns\ValidatesListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexListFilterOptionsRequest extends FormRequest
{
    use ValidatesListFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $fields = match ($action) {
            'taskUsers' => ['assignee_id', 'follower_id', 'supervisor_id', 'user_id', 'financial_provider_user_id', 'equipment_provider_user_id'],
            'reportUsers' => ['recipient_user_id', 'creator_user_id'],
            default => [],
        };

        return [
            ...$this->textFilterRules(['search']),
            ...$this->paginationRules(),
            'field' => ['nullable', Rule::in($fields)],
            ...($action === 'taskUsers' ? [
                'submission_type' => ['nullable', Rule::enum(TaskSubmissionType::class)],
                'scope' => ['nullable', Rule::in(['created_by_me', 'assigned_to_me', 'involved', 'action_required', 'all'])],
            ] : []),
        ];
    }
}
