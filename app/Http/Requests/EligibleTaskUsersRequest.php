<?php

namespace App\Http\Requests;

use App\Enums\TaskSubmissionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EligibleTaskUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'submission_type' => ['sometimes', Rule::enum(TaskSubmissionType::class)],
            'task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
        ];
    }
}
