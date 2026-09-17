<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkMeetingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['task_id' => ['required', 'integer', Rule::exists('tasks', 'id')]];
    }

    public function messages(): array
    {
        return [
            'task_id.exists' => 'تسک انتخاب‌شده وجود ندارد.',
        ];
    }
}
