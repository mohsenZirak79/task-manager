<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agenda_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('meeting_agenda_items', 'id')],
            'task_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id'),
                Rule::unique('meeting_resolutions', 'task_id')->ignore($this->route('resolution')?->id),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'agenda_item_id.exists' => 'دستور جلسه انتخاب‌شده معتبر نیست.',
            'task_id.exists' => 'تسک انتخاب‌شده معتبر نیست.',
            'task_id.unique' => 'این تسک قبلاً به مصوبه دیگری متصل شده است.',
        ];
    }
}
