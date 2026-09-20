<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Enums\TaskSubmissionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $createdToRules = ['sometimes', 'date'];
        if ($this->filled('created_from')) {
            $createdToRules[] = 'after_or_equal:created_from';
        }

        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'submission_type' => ['sometimes', Rule::enum(TaskSubmissionType::class)],
            'scope' => ['sometimes', Rule::in([
                'created_by_me', 'assigned_to_me', 'involved', 'action_required', 'all',
            ])],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'assignee_id' => ['sometimes', 'integer', 'exists:users,id'],
            'follower_id' => ['sometimes', 'integer', 'exists:users,id'],
            'supervisor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'tag' => ['sometimes', 'string', 'max:100'],
            'due_from' => ['sometimes', 'date'],
            'due_to' => ['sometimes', 'date', 'after_or_equal:due_from'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => $createdToRules,
            'sort' => ['sometimes', Rule::in([
                'newest', 'oldest',
                'longest_duration', 'shortest_duration',
                'highest_progress', 'lowest_progress',
            ])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
