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
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
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
